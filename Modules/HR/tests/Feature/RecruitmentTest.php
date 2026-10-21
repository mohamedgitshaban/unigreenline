<?php

namespace Modules\HR\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\HR\Models\Applicant;
use Modules\HR\Models\Department;
use Modules\HR\Models\Employee;
use Modules\HR\Models\JobPosition;
use Modules\HR\Models\RecruitmentStage;
use Tests\TestCase;

class RecruitmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private JobPosition $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->hr = $this->user('HR Manager');
        Sanctum::actingAs($this->hr);
        $this->job = JobPosition::factory()->recycle($this->tenant)->create(['name' => 'Lab Technician', 'vacancies' => 2, 'recruiter_id' => $this->hr->id, 'department_id' => Department::factory()->recycle($this->tenant)->create(['name' => 'Laboratory'])->id]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);

        return $user;
    }

    private function stage(string $name): RecruitmentStage
    {
        $this->getJson('/api/v1/recruitment-stages')->assertOk();

        return RecruitmentStage::query()->where('tenant_id', $this->tenant->id)->where('name', $name)->firstOrFail();
    }

    private function apply(array $overrides = []): string
    {
        return $this->postJson('/api/v1/applicants', $overrides + ['name' => 'Salma Hassan', 'email' => 'salma@example.com', 'job_position_id' => $this->job->id, 'expected_salary' => 9000, 'source' => 'LinkedIn'])->assertCreated()->json('data.id');
    }

    public function test_a_company_starts_with_the_standard_pipeline_in_order(): void
    {
        $stages = $this->getJson('/api/v1/recruitment-stages')->assertOk()->json('data');

        $this->assertSame(['New', 'Initial Qualification', 'First Interview', 'Second Interview', 'Contract Proposal', 'Contract Signed'], array_column($stages, 'name'));
        $this->assertSame([false, false, false, false, false, true], array_column($stages, 'is_hired'));
        $this->assertCount(6, $this->getJson('/api/v1/recruitment-stages')->json('data'), 'created once');
        $this->assertCount(5, $this->getJson('/api/v1/refuse-reasons')->json('data'));
    }

    public function test_an_applicant_enters_the_first_stage_with_the_jobs_recruiter_and_a_history(): void
    {
        $id = $this->apply();

        $this->getJson("/api/v1/applicants/{$id}")->assertOk()
            ->assertJsonPath('data.stage.name', 'New')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.job_position.name', 'Lab Technician')
            ->assertJsonPath('data.recruiter.id', $this->hr->id)
            ->assertJsonPath('data.applied_on', now()->toDateString())
            ->assertJsonPath('data.expected_salary', '9000.00');

        $this->getJson("/api/v1/applicants/{$id}/history")->assertOk()->assertJsonPath('data.0.type', 'created')->assertJsonPath('data.0.summary', 'Applied for Lab Technician');
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Applicant', 'entity_id' => $id, 'operation' => 'INSERT']);
    }

    public function test_an_application_is_validated_and_kept_inside_the_company(): void
    {
        $this->postJson('/api/v1/applicants', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/applicants', ['name' => 'X', 'job_position_id' => JobPosition::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('job_position_id');
        $this->postJson('/api/v1/applicants', ['name' => 'X', 'stage_id' => RecruitmentStage::factory()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('stage_id');
        $this->postJson('/api/v1/applicants', ['name' => 'X', 'priority' => 9, 'email' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['priority', 'email']);
    }

    public function test_moving_an_applicant_between_stages_is_recorded(): void
    {
        $id = $this->apply();
        $interview = $this->stage('First Interview');

        $this->postJson("/api/v1/applicants/{$id}/move", ['stage_id' => $interview->id])->assertOk()->assertJsonPath('data.stage.name', 'First Interview');

        $this->getJson("/api/v1/applicants/{$id}/history")->assertJsonPath('data.0.type', 'stage_changed')->assertJsonPath('data.0.summary', 'Moved from New to First Interview');
        $this->postJson("/api/v1/applicants/{$id}/move", ['stage_id' => RecruitmentStage::factory()->create()->id])->assertUnprocessable();
        $this->postJson("/api/v1/applicants/{$id}/move", ['stage_id' => $interview->id])->assertOk();
        $this->assertSame(2, $this->getJson("/api/v1/applicants/{$id}/history")->json('meta.total'), 'moving to the same stage changes nothing');
    }

    public function test_refusing_archiving_and_restoring(): void
    {
        $id = $this->apply();
        $reason = $this->getJson('/api/v1/refuse-reasons')->json('data.1.id');

        $this->postJson("/api/v1/applicants/{$id}/refuse", ['refuse_reason_id' => $reason, 'note' => 'Wants more'])->assertOk()->assertJsonPath('data.status', 'refused')->assertJsonPath('data.refuse_reason.id', $reason);
        $this->postJson("/api/v1/applicants/{$id}/move", ['stage_id' => $this->stage('First Interview')->id])->assertUnprocessable();
        $this->postJson("/api/v1/applicants/{$id}/refuse")->assertUnprocessable();

        $this->postJson("/api/v1/applicants/{$id}/restore")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.refuse_reason', null);
        $this->postJson("/api/v1/applicants/{$id}/restore")->assertUnprocessable();

        $this->postJson("/api/v1/applicants/{$id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson("/api/v1/applicants/{$id}/restore")->assertOk();
    }

    public function test_an_offer_is_made_and_hiring_creates_the_employee(): void
    {
        $id = $this->apply();

        $this->postJson("/api/v1/applicants/{$id}/offer", ['offer_status' => 'sent', 'proposed_salary' => 9500, 'offer_valid_until' => now()->addWeek()->toDateString()])
            ->assertOk()->assertJsonPath('data.offer_status', 'sent')->assertJsonPath('data.proposed_salary', '9500.00');

        $this->postJson("/api/v1/applicants/{$id}/hire", ['hire_date' => '2026-12-01'])
            ->assertOk()->assertJsonPath('data.status', 'hired')->assertJsonPath('data.stage.name', 'Contract Signed')->assertJsonPath('data.offer_status', 'accepted')->assertJsonPath('data.employee.code', 'EMP-00001');

        $employee = Employee::query()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('Salma Hassan', $employee->name);
        $this->assertSame('Lab Technician', $employee->job_title);
        $this->assertSame('salma@example.com', $employee->email);
        $this->assertEquals(9500, $employee->basic_salary);
        $this->assertSame('2026-12-01', $employee->hire_date->toDateString());
        $this->assertSame('Laboratory', Department::query()->find($employee->department_id)->name);
        $this->assertSame(1, $this->job->fresh()->hired_count);
        $this->assertSame('open', $this->job->fresh()->status, 'one of two vacancies is still open');
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Employee', 'entity_id' => $employee->id, 'operation' => 'INSERT']);

        $this->postJson("/api/v1/applicants/{$id}/hire", ['hire_date' => '2026-12-01'])->assertUnprocessable()->assertJsonPath('message', 'This applicant has already been hired.');
        $this->deleteJson("/api/v1/applicants/{$id}")->assertUnprocessable();
    }

    public function test_filling_the_last_vacancy_closes_the_job_and_codes_do_not_repeat(): void
    {
        $first = $this->apply(['name' => 'One']);
        $second = $this->apply(['name' => 'Two', 'email' => 'two@example.com']);

        $this->postJson("/api/v1/applicants/{$first}/hire", ['hire_date' => '2026-12-01'])->assertOk();
        $this->postJson("/api/v1/applicants/{$second}/hire", ['hire_date' => '2026-12-01', 'basic_salary' => 8000])->assertOk()->assertJsonPath('data.employee.code', 'EMP-00002');

        $this->assertSame('closed', $this->job->fresh()->status);
        $this->assertSame(2, $this->job->fresh()->hired_count);
    }

    public function test_hiring_needs_a_salary_and_a_free_code(): void
    {
        $id = $this->apply(['expected_salary' => null]);

        $this->postJson("/api/v1/applicants/{$id}/hire", ['hire_date' => '2026-12-01'])->assertUnprocessable()->assertJsonPath('message', 'Say what the salary is: there is no proposed or expected salary on the applicant.');
        $this->postJson("/api/v1/applicants/{$id}/hire", [])->assertUnprocessable()->assertJsonValidationErrors('hire_date');

        Employee::factory()->recycle($this->tenant)->create(['code' => 'EMP-777']);
        $this->postJson("/api/v1/applicants/{$id}/hire", ['hire_date' => '2026-12-01', 'basic_salary' => 5000, 'code' => 'EMP-777'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson("/api/v1/applicants/{$id}/hire", ['hire_date' => '2026-12-01', 'basic_salary' => 5000, 'code' => 'EMP-800'])->assertOk()->assertJsonPath('data.employee.code', 'EMP-800');
    }

    public function test_interviews_are_scheduled_recorded_and_tell_the_interviewer(): void
    {
        $id = $this->apply();
        $interviewer = $this->user('Employee');
        $interviewer->givePermissionTo('interviews.view');

        $interviewId = $this->postJson('/api/v1/interviews', ['applicant_id' => $id, 'interviewer_id' => $interviewer->id, 'scheduled_at' => now()->addDays(2)->toIso8601String(), 'mode' => 'video', 'duration_minutes' => 30])
            ->assertCreated()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.applicant.name', 'Salma Hassan')->json('data.id');

        $this->assertSame('Interview added', Notification::query()->where('user_id', $interviewer->id)->sole()->title);
        $this->getJson("/api/v1/applicants/{$id}/history")->assertJsonPath('data.0.type', 'interview');

        $this->postJson("/api/v1/interviews/{$interviewId}/record-result", ['result' => 'pass', 'score' => 8, 'feedback' => 'Strong'])
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.result', 'pass')->assertJsonPath('data.score', 8);
        $this->postJson("/api/v1/interviews/{$interviewId}/record-result", ['result' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('result');

        $this->getJson("/api/v1/interviews?applicant_id={$id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/interviews?mine=1')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/interviews?status=scheduled')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/interviews', ['applicant_id' => Applicant::factory()->create()->id, 'scheduled_at' => now()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('applicant_id');
    }

    public function test_a_cv_can_be_attached_and_downloaded(): void
    {
        Storage::fake();
        $id = $this->apply();

        $this->postJson("/api/v1/applicants/{$id}/resume", ['file' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf')])->assertOk()->assertJsonPath('data.resume_name', 'cv.pdf');
        $this->get("/api/v1/applicants/{$id}/resume")->assertOk()->assertDownload('cv.pdf');
        $this->postJson("/api/v1/applicants/{$id}/resume", ['file' => UploadedFile::fake()->create('cv.exe', 10)])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson("/api/v1/applicants/{$id}/resume", ['file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')])->assertUnprocessable();

        $other = $this->apply(['name' => 'No CV', 'email' => 'x@example.com']);
        $this->get("/api/v1/applicants/{$other}/resume")->assertNotFound();
    }

    public function test_notes_go_in_the_history(): void
    {
        $id = $this->apply();

        $this->postJson("/api/v1/applicants/{$id}/notes", ['text' => 'Called, will send portfolio'])->assertCreated()->assertJsonPath('data.type', 'note');
        $this->postJson("/api/v1/applicants/{$id}/notes", [])->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->getJson("/api/v1/applicants/{$id}/history")->assertJsonPath('data.0.summary', 'Called, will send portfolio')->assertJsonPath('data.0.user.name', $this->hr->name);
    }

    public function test_the_pipeline_shows_each_stage_with_its_applicants(): void
    {
        $a = $this->apply(['name' => 'Alpha', 'priority' => 3]);
        $this->apply(['name' => 'Beta', 'email' => 'b@example.com']);
        $this->postJson("/api/v1/applicants/{$a}/move", ['stage_id' => $this->stage('First Interview')->id]);
        $refused = $this->apply(['name' => 'Gone', 'email' => 'g@example.com']);
        $this->postJson("/api/v1/applicants/{$refused}/refuse");

        $data = $this->getJson('/api/v1/recruitment/pipeline')->assertOk()->json('data');
        $stages = collect($data['stages'])->keyBy('name');

        $this->assertSame(1, $stages['New']['count']);
        $this->assertSame(['Beta'], array_column($stages['New']['applicants'], 'name'));
        $this->assertSame(['Alpha'], array_column($stages['First Interview']['applicants'], 'name'));
        $this->assertSame(['active' => 2, 'hired' => 0, 'refused' => 1, 'archived' => 0], $data['totals']);
        $this->getJson('/api/v1/recruitment/pipeline?job_position_id='.JobPosition::factory()->recycle($this->tenant)->create()->id)->assertJsonPath('data.totals.active', 0);
    }

    public function test_the_analysis_counts_applications_hires_sources_and_refusals(): void
    {
        $one = $this->apply(['name' => 'One', 'source' => 'LinkedIn']);
        $this->apply(['name' => 'Two', 'email' => '2@example.com', 'source' => 'LinkedIn']);
        $three = $this->apply(['name' => 'Three', 'email' => '3@example.com', 'source' => 'Referral']);
        $reason = collect($this->getJson('/api/v1/refuse-reasons')->json('data'))->firstWhere('name', "Doesn't fit the job requirements")['id'];
        $this->postJson("/api/v1/applicants/{$one}/offer", ['offer_status' => 'sent', 'proposed_salary' => 9000]);
        $this->postJson("/api/v1/applicants/{$one}/hire", ['hire_date' => now()->toDateString()])->assertOk();
        $this->postJson("/api/v1/applicants/{$three}/refuse", ['refuse_reason_id' => $reason]);

        $data = $this->getJson('/api/v1/recruitment/analysis')->assertOk()->json('data');

        $this->assertSame(3, $data['applications']);
        $this->assertSame(1, $data['hired']);
        $this->assertSame(1, $data['refused']);
        $this->assertEquals(0, $data['average_days_to_hire']);
        $this->assertEquals(100.0, $data['offer_acceptance_pct']);
        $this->assertSame(['LinkedIn' => 2, 'Referral' => 1], collect($data['by_source'])->pluck('applicants', 'source')->all());
        $this->assertSame(1, (int) collect($data['by_source'])->firstWhere('source', 'LinkedIn')['hired']);
        $this->assertSame("Doesn't fit the job requirements", $data['refusal_reasons'][0]['reason']);
        $this->assertSame(['name' => 'Lab Technician', 'still_open' => 1, 'active_applicants' => 1], array_intersect_key($data['jobs'][0], array_flip(['name', 'still_open', 'active_applicants'])));
    }

    public function test_lists_filter_search_and_export(): void
    {
        $this->apply(['name' => 'Alpha', 'source' => 'Referral', 'priority' => 2]);
        $this->apply(['name' => 'Beta', 'email' => 'b@example.com']);

        $this->getJson('/api/v1/applicants?search=alph')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/applicants?priority=2')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/applicants?source=LinkedIn')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/applicants?job_position_id={$this->job->id}&status=active")->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/applicants?mine=1')->assertJsonCount(2, 'data')->assertJsonPath('data.0.interviews_count', 0);
        $this->get('/api/v1/applicants?export=csv')->assertOk()->assertDownload();
        $this->getJson('/api/v1/job-positions')->assertJsonPath('data.0.applicants_count', 2)->assertJsonPath('data.0.active_applicants_count', 2);
    }

    public function test_jobs_and_stages_are_managed_with_their_rules(): void
    {
        $this->postJson('/api/v1/job-positions', ['name' => 'Driver', 'vacancies' => 3, 'salary_min' => 5000, 'salary_max' => 4000])->assertUnprocessable()->assertJsonValidationErrors('salary_max');
        $jobId = $this->postJson('/api/v1/job-positions', ['name' => 'Driver', 'vacancies' => 3, 'employment_type' => 'contract'])->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
        $this->putJson("/api/v1/job-positions/{$jobId}", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->deleteJson("/api/v1/job-positions/{$jobId}")->assertNoContent();

        $this->apply();
        $this->deleteJson("/api/v1/job-positions/{$this->job->id}")->assertUnprocessable();

        $stageId = $this->postJson('/api/v1/recruitment-stages', ['name' => 'Trial day', 'sequence' => 35])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/recruitment-stages', ['name' => 'Trial day'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/v1/recruitment-stages/{$stageId}", ['is_hired' => true])->assertOk();
        $this->assertSame(1, RecruitmentStage::query()->where('tenant_id', $this->tenant->id)->where('is_hired', true)->count(), 'only one stage is the contract-signed stage');
        $this->deleteJson('/api/v1/recruitment-stages/'.$this->stage('New')->id)->assertStatus(409);
        $this->deleteJson("/api/v1/recruitment-stages/{$stageId}")->assertNoContent();
    }

    public function test_the_recruiter_hears_about_new_applicants_and_other_companys_data_is_hidden(): void
    {
        Sanctum::actingAs($this->user('Administrator'));
        $id = $this->apply();

        $note = Notification::query()->where('user_id', $this->hr->id)->where('link_entity', 'Applicant')->sole();
        $this->assertSame('Applicant added', $note->title);
        $this->assertSame("/api/v1/applicants/{$id}", $note->link_url);

        $foreign = Applicant::factory()->create();
        $this->getJson("/api/v1/applicants/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/applicants/{$foreign->id}/hire", ['hire_date' => '2026-12-01'])->assertNotFound();
        $this->putJson("/api/v1/applicants/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    }

    public function test_only_hr_may_use_recruitment_and_only_with_the_right_permission_to_hire(): void
    {
        $id = $this->apply();
        $qualification = $this->stage('Initial Qualification')->id;

        Sanctum::actingAs($this->user('Sales Rep'));
        $this->getJson('/api/v1/applicants')->assertForbidden();
        $this->getJson('/api/v1/recruitment/pipeline')->assertForbidden();
        $this->getJson('/api/v1/dashboards/hr')->assertForbidden();

        $recruiter = $this->user('Employee');
        $recruiter->givePermissionTo(['applicants.view', 'applicants.move']);
        Sanctum::actingAs($recruiter);
        $this->postJson("/api/v1/applicants/{$id}/move", ['stage_id' => $qualification])->assertOk();
        $this->postJson("/api/v1/applicants/{$id}/hire", ['hire_date' => '2026-12-01'])->assertForbidden();
        $this->postJson('/api/v1/applicants', ['name' => 'x'])->assertForbidden();
    }

    public function test_the_hr_dashboard_shows_recruitment(): void
    {
        $this->apply();
        $this->postJson('/api/v1/interviews', ['applicant_id' => Applicant::query()->first()->id, 'scheduled_at' => now()->addDay()->toIso8601String()])->assertCreated();

        $data = $this->getJson('/api/v1/dashboards/hr?features=recruitment')->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');

        $this->assertSame(1, $kpis['open_positions']['value']);
        $this->assertSame(2, $kpis['open_vacancies']['value']);
        $this->assertSame(1, $kpis['applications']['value']);
        $this->assertSame(1, $kpis['active_applicants']['value']);
        $this->assertSame(1, $kpis['interviews_this_week']['value']);
        $this->assertSame(['New' => 1], collect(collect($data['charts'])->firstWhere('key', 'recruitment_funnel')['series'])->pluck('value', 'label')->filter()->all());
    }
}
