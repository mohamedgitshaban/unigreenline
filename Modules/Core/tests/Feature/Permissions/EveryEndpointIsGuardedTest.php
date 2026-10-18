<?php

namespace Modules\Core\Tests\Feature\Permissions;

use Illuminate\Support\Facades\Route;
use Modules\Core\Support\PermissionCatalogue;
use Tests\TestCase;

class EveryEndpointIsGuardedTest extends TestCase
{
    /**
     * Endpoints that are deliberately open to any signed-in (or no) user:
     * authentication itself, a user's own inbox, shared reference lists and
     * saving one's own abandoned form.
     *
     * @var array<int, string>
     */
    private const UNGUARDED = [
        'POST api/v1/auth/login', 'POST api/v1/auth/logout', 'GET api/v1/auth/me', 'POST api/v1/auth/change-password',
        'GET api/v1/notifications', 'PUT api/v1/notifications/read-all', 'GET api/v1/notifications/{id}',
        'GET api/v1/governorates', 'GET api/v1/governorates/{governorate}/cities',
        'POST api/v1/discarded-actions',
    ];

    public function test_every_api_endpoint_declares_the_permission_that_guards_it(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $signature = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first().' '.$route->uri();

            $permissions = array_filter(PermissionCatalogue::abilitiesOf($route), fn ($a) => $a !== $route->getAction('export_permission'));

            if ($permissions === [] && ! in_array($signature, self::UNGUARDED, true)) {
                $unguarded[] = $signature;
            }
        }

        $this->assertSame([], $unguarded, "These endpoints have no permission:\n".implode("\n", $unguarded));
    }

    public function test_permissions_follow_the_resource_dot_action_naming(): void
    {
        foreach (PermissionCatalogue::names() as $name) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z-]*\.[a-z][a-z-]*$/', $name);
        }
    }

    public function test_every_list_that_can_export_declares_its_export_permission(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $export = $route->getAction('export_permission');

            if ($export !== null) {
                $this->assertStringEndsWith('.export', $export);
                $this->assertContains('GET', $route->methods());
            }
        }
    }
}
