# HR 🆕 NEW

> 🆕 **NEW module.** Every endpoint on this page is new.

Employees, attendance, work schedules, global holidays, time permissions (come
late / leave early / partial absence), leave, and a **monthly payroll that posts
to the chart of accounts** — in the spirit of Odoo's HR apps, sized for this ERP.

Every endpoint needs its own permission (`employees.view`, `payroll-runs.approve`
...) so a role can be given exactly the actions it should have — the full table is
in [permissions.md](./permissions.md#hr). List endpoints take the usual
`search`, `sort`, `per_page` and filter parameters from the [README](./README.md).

| Role | What it can do |
|---|---|
| `HR Manager` 🆕 | Everything in HR: employees, schedules, holidays, attendance, approve/reject permissions and leave, generate/approve/pay payroll |
| `Employee` 🆕 | **Self-service**: check in/out, see their own attendance and (approved) payslips, raise and manage their own pending leave and time permissions, see holidays |
| `Accountant` | `payroll-runs.view`, `payslips.view` (read the payroll that hits the books) |
| `Owner` | view everything in HR, approve/reject leave and permissions, approve/pay payroll |
| `Auditor` | view/export everything in HR |
| `Administrator` | everything |

### Self-service scoping

A user who does **not** hold `employees.view` only ever sees and acts on **their
own** employee record — the employee whose `user_id` is that login:

- `GET /attendances`, `/attendances/summary`, `/time-permissions`, `/leave-requests` return only their rows;
- another employee's record is **404** (not 403 — existence isn't leaked);
- creating a leave request / time permission always targets themselves (`employee_id` is ignored/optional);
- if the login isn't linked to an employee record: **422** "Your login is not linked to an employee record. Ask HR to link it.";
- salary fields on an employee (`basic_salary`, `allowances`, `insurance_pct`, `tax_pct`) are only returned to users with `payroll-runs.view`.

A user **with** `employees.view` (HR Manager, Owner, Auditor) sees everyone and must
send `employee_id` when creating a request for someone.

Business-rule refusals are always **422** `{"message": "..."}`; unknown or
other-tenant ids are **404**.

---

## How the pieces connect

```
Work schedule ─┐
Holidays ──────┼─► Attendance (late / early / overtime minutes) ─┐
Time permission┘                                                  ├─► Payroll run ─► Payslips
Leave ───────────────────────────────────────────────────────────┘        │
                                                                          ▼
                                        approve:  Dr 6100 Salaries Expense
                                                  Cr 2210 Payroll Taxes & Insurance Payable
                                                  Cr 2200 Salaries Payable
                                        pay:      Dr 2200 Salaries Payable
                                                  Cr 1110 Cash  (or 1120 Bank)
```

Accounts `6100`, `2200` and `2210` are looked up by code — run `AccountSeeder` so
the new ones exist (see [accounting.md](./accounting.md#finance-review--what-was-missing-and-what-changed)).

---

## Departments

### `GET /api/v1/departments` · `GET /api/v1/departments/{id}`

Requires `departments.view`. Search matches `name`.

```json
{ "data": { "id": "01m...", "name": "Sales", "active": true, "employees_count": 8 } }
```

### `POST /api/v1/departments`

Requires `departments.create`. `{ "name": "Sales", "active": true }` — `name` unique
within the company (**422**). **201**.

### `PUT /api/v1/departments/{id}` · `DELETE /api/v1/departments/{id}`

Requires `departments.update` / `departments.delete`. Delete is **204**; the
department's employees simply end up with no department.

---

## Work schedules

The working day an employee is measured against. An employee without a schedule
uses the company's `is_default` one (the seeder creates **Standard**: 09:00–17:00,
15 minutes grace, Friday/Saturday weekend).

```json
{
  "id": "01m...", "name": "Standard", "start_time": "09:00", "end_time": "17:00",
  "break_minutes": 0, "grace_minutes": 15, "weekend_days": [5, 6],
  "scheduled_minutes": 480, "is_default": true
}
```

`scheduled_minutes` = end − start − break. `weekend_days`: `0` Sunday … `6` Saturday.

### `GET /api/v1/work-schedules` · `GET /api/v1/work-schedules/{id}`

Requires `work-schedules.view`.

### `POST /api/v1/work-schedules`

Requires `work-schedules.create`.

```json
{ "name": "Shift A", "start_time": "08:00", "end_time": "16:00", "break_minutes": 30, "grace_minutes": 10, "weekend_days": [5], "is_default": false }
```

`end_time` must be after `start_time`; `weekend_days` is required (distinct 0–6);
`name` unique per company. Setting `is_default: true` demotes the previous default.
**201**.

### `PUT /api/v1/work-schedules/{id}` · `DELETE /api/v1/work-schedules/{id}`

Requires `work-schedules.update` / `work-schedules.delete` (**204**). Employees who used a
deleted schedule fall back to the company's default one.

---

## Global holidays

Company-wide days off. They are paid, never count as absence, and are skipped when
counting leave days.

```json
{ "id": "01m...", "name": "Revolution Day", "start_date": "2026-07-23", "end_date": "2026-07-23" }
```

### `GET /api/v1/holidays` · `GET /api/v1/holidays/{id}`

Requires `holidays.view` (employees can read the holiday calendar).

### `POST /api/v1/holidays`

Requires `holidays.create`. `{ "name": "Eid al-Fitr", "start_date": "2026-03-20", "end_date": "2026-03-22" }`
— a range of days; `end_date >= start_date`. **201**.

### `PUT /api/v1/holidays/{id}` · `DELETE /api/v1/holidays/{id}`

Requires `holidays.update` / `holidays.delete`. **204** on delete.

> Holidays only affect months that have **not** been through payroll yet — a
> draft run can be recalculated; an approved one is final.

---

## Employees

```json
{
  "id": "01m...", "code": "E-014", "name": "Mona Adel", "email": "mona@example.com",
  "phone": "0100...", "national_id": "29001011234567", "job_title": "Sales Executive",
  "user_id": "01m...", "department": { "id": "01m...", "name": "Sales" },
  "work_schedule": { "id": "01m...", "name": "Standard" },
  "hire_date": "2025-02-01", "termination_date": null, "status": "active",
  "overtime_eligible": false, "annual_leave_days": 21,
  "basic_salary": "9000.00", "allowances": "1000.00", "insurance_pct": "11.00", "tax_pct": "10.00",
  "created_at": "2026-10-18T09:00:00.000000Z"
}
```

`basic_salary`, `allowances`, `insurance_pct`, `tax_pct` only appear for users holding `payroll-runs.view`.

### `GET /api/v1/employees`

Requires `employees.view`. Search matches code, name, email, phone, job title and
department. `?export=csv|xlsx` needs `employees.export`.

### `GET /api/v1/employees/{id}`

Requires `employees.view`. **404** for another company's employee.

### `GET /api/v1/employees/{id}/leave-balance`

Requires `employees.view`. Optional `?year=2026` (default this year). Annual leave
only — sick/unpaid/other don't use the entitlement.

```json
{ "data": { "employee_id": "01m...", "year": 2026, "entitlement": 21, "taken": 5, "pending": 2, "remaining": 14 } }
```

`taken` = approved annual leave days, `pending` = awaiting a decision, `remaining` = entitlement − taken − pending.

### `POST /api/v1/employees`

Requires `employees.create`.

```json
{
  "code": "E-014", "name": "Mona Adel", "hire_date": "2025-02-01", "basic_salary": 9000,
  "allowances": 1000, "insurance_pct": 11, "tax_pct": 10, "overtime_eligible": false,
  "annual_leave_days": 21, "job_title": "Sales Executive", "department_id": "01m...",
  "work_schedule_id": null, "user_id": "01m...", "email": null, "phone": null, "national_id": null
}
```

- required: `code` (unique per company), `name`, `hire_date`, `basic_salary`;
- `user_id` links the employee to a **login** so they can check in and see their own data — one employee per login (**422** otherwise);
- `status`: `active` (default) / `inactive`; `termination_date >= hire_date`;
- `insurance_pct` / `tax_pct`: 0–100, default `0`; `annual_leave_days` default `21`;
- `overtime_eligible` defaults to `false` — overtime is paid only when this is `true`;
- **201**; audit-logged.

### `PUT /api/v1/employees/{id}` · `DELETE /api/v1/employees/{id}`

Requires `employees.update` / `employees.delete`. Same fields, all optional.
An employee who already has attendance, leave or payslips can't be deleted (**409**) —
set a `termination_date` and `status: "inactive"` instead. Pay changes apply
from the next payroll run that is calculated.

---

## Attendance

One row per employee per day.

```json
{
  "id": "01m...", "employee": { "id": "01m...", "code": "E-014", "name": "Mona Adel" },
  "work_date": "2026-10-12", "check_in": "09:20", "check_out": "16:30", "source": "self",
  "notes": null, "scheduled_minutes": 480, "worked_minutes": 430, "late_minutes": 20,
  "early_leave_minutes": 30, "overtime_minutes": 0, "incomplete": false
}
```

The minutes are worked out against the employee's schedule: **late** = after the
start, **early leave** = before the end, **overtime** = after the end,
**worked** = check-out − check-in − break (`null` until checked out). `source` is
`self` (checked in by the employee) or `manual` (keyed in by HR). `incomplete` is
true for a day checked in but not out.

### `GET /api/v1/attendances`

Requires `attendances.view`. Search matches employee name/code and notes.
Self-service users see only their own days. `?export=csv|xlsx` needs `attendances.export`.

### `GET /api/v1/attendances/summary`

Requires `attendances.view`. `?month=2026-10` (default this month) and optional `employee_id`.
Self-service users see only themselves.

```json
{ "data": { "month": "2026-10", "employees": [
  { "employee": { "id": "01m...", "code": "E-014", "name": "Mona Adel" },
    "days_present": 14, "incomplete_days": 1, "worked_minutes": 6020,
    "late_minutes": 55, "early_leave_minutes": 30, "overtime_minutes": 90 }
] } }
```

### `GET /api/v1/attendances/{id}`

Requires `attendances.view`. **404** for another company's record, or (self-service) someone else's.

### `POST /api/v1/attendances/check-in`

Requires `attendances.check-in`. **No body** — the signed-in employee starts their
day now. **201** — today's record (`source: "self"`).
**422** "You have already checked in today." / "Your login is not linked to an employee record. Ask HR to link it."

### `POST /api/v1/attendances/check-out`

Requires `attendances.check-out`. **No body.** Ends today's day and calculates the
minutes. **200**. **422** "You have not checked in today." / "You have already checked out today."

### `POST /api/v1/attendances`

Requires `attendances.create`. HR keying a day in by hand (a forgotten check-in).

```json
{ "employee_id": "01m...", "work_date": "2026-10-12", "check_in": "09:20", "check_out": "16:30", "notes": "Site visit" }
```

`work_date` can't be in the future; `check_out` requires `check_in` and must be
after it; **422** if that employee already has a record for that date. **201**.

### `PUT /api/v1/attendances/{id}` · `DELETE /api/v1/attendances/{id}`

Requires `attendances.update` / `attendances.delete`. Editing recalculates the
minutes. Correct attendance **before** the month's payroll is approved.

---

## Time permissions (come late / leave early / partial attend)

An approved permission **excuses** the matching minutes, so they aren't deducted
from pay. It is *approval*, not the time itself, that excuses it.

| `type` | Use for |
|---|---|
| `late_arrival` | arriving after the start |
| `early_leave` | leaving before the end |
| `partial_absence` | being away for part of the day |

```json
{
  "id": "01m...", "employee": { "id": "01m...", "code": "E-014", "name": "Mona Adel" },
  "type": "late_arrival", "permission_date": "2026-10-12", "from_time": "09:00", "to_time": "10:00",
  "minutes": 60, "reason": "Doctor's appointment", "status": "pending",
  "decided_by": null, "decided_at": null, "rejection_reason": null, "created_at": "..."
}
```

`status`: `pending` → `approved` / `rejected`.

### `GET /api/v1/time-permissions` · `GET /api/v1/time-permissions/{id}`

Requires `time-permissions.view`. Self-service users see only their own.

### `POST /api/v1/time-permissions`

Requires `time-permissions.create`.

```json
{ "employee_id": null, "type": "late_arrival", "permission_date": "2026-10-12", "from_time": "09:00", "to_time": "10:00", "reason": "Doctor's appointment" }
```

`to_time` after `from_time`. `employee_id` is required for HR, ignored for a
self-service user (always themselves). **201**, `status: "pending"`.

### `PUT /api/v1/time-permissions/{id}` · `DELETE /api/v1/time-permissions/{id}`

Requires `time-permissions.update` / `time-permissions.delete`. **Pending only** —
otherwise **422** "Only a pending permission can be changed." / "Only a pending request can be deleted."

### `POST /api/v1/time-permissions/{id}/approve` · `POST /api/v1/time-permissions/{id}/reject`

Requires `time-permissions.approve` / `time-permissions.reject`. Reject takes an optional
`{ "reason": "..." }`. Pending only (**422** otherwise). Returns the permission.

---

## Leave

| `type` | Pay |
|---|---|
| `annual` | paid; uses the annual entitlement |
| `sick` | paid |
| `other` | paid |
| `unpaid` | **costs a day's salary** per working day |

```json
{
  "id": "01m...", "employee": { "id": "01m...", "code": "E-014", "name": "Mona Adel" },
  "type": "annual", "paid": true, "start_date": "2026-11-01", "end_date": "2026-11-03",
  "days": 3, "reason": "Family trip", "status": "pending",
  "decided_by": null, "decided_at": null, "rejection_reason": null, "created_at": "..."
}
```

`days` counts **working days only** — the employee's weekend and company holidays inside the range don't count.

### `GET /api/v1/leave-requests` · `GET /api/v1/leave-requests/{id}`

Requires `leave-requests.view`. Self-service users see only their own.

### `POST /api/v1/leave-requests`

Requires `leave-requests.create`.

```json
{ "employee_id": null, "type": "annual", "start_date": "2026-11-01", "end_date": "2026-11-03", "reason": "Family trip" }
```

**422** when:
- the range has no working days — "The range has no working days — it is all weekend or holiday." (`start_date`);
- the employee already has pending/approved leave overlapping the range (`start_date`);
- annual leave exceeds the balance — "That is N day(s) of annual leave but only M remain for the year." (`end_date`).

**201**, `status: "pending"`.

### `PUT /api/v1/leave-requests/{id}` · `DELETE /api/v1/leave-requests/{id}`

Requires `leave-requests.update` / `leave-requests.delete`. **Pending only**
(**422** "Only a pending leave request can be changed." / "Only a pending request can be deleted."). Edits are re-validated like a create.

### `POST /api/v1/leave-requests/{id}/approve` · `POST /api/v1/leave-requests/{id}/reject`

Requires `leave-requests.approve` / `leave-requests.reject`. Reject takes an optional `{ "reason": "..." }`.
Pending only. Approved leave stops the days counting as absence in payroll.

---

## Payroll

A month is paid as a **fixed salary** (`basic_salary + allowances`), adjusted by what
actually happened. Rules the calculator follows:

| Item | Rule |
|---|---|
| Day rate | fixed salary ÷ 30 (whatever the month's length) |
| Hour rate | day rate ÷ the schedule's working hours |
| Weekend / company holiday | paid, no effect |
| Absent (no attendance, no approved leave) | deduct one day |
| Approved paid leave (annual / sick / other) | no deduction |
| Approved unpaid leave | deduct one day |
| Late / left early / gap | the shortfall against the schedule is deducted at the hour rate, **after** subtracting minutes covered by an approved time permission and the schedule's daily grace minutes |
| Day checked in but never out | `incomplete` — flagged, nothing deducted (fix it, then recalculate) |
| Overtime | paid at hour rate × 1.5 — **only** for `overtime_eligible` employees |
| Joined / left mid-month | salary prorated by the days employed |
| Insurance | `insurance_pct` % of the (prorated) basic salary |
| Tax | `tax_pct` % of what remains after attendance deductions and insurance |
| Net pay | earned − insurance − tax |

The factors (30 days, ×1.5) are in `Modules/HR/config/config.php`.

### Payroll run object

```json
{
  "id": "01m...", "period": "2026-09", "period_start": "2026-09-01", "period_end": "2026-09-30",
  "status": "draft", "employees_count": 12,
  "total_earned": "118400.00", "total_withheld": "21300.00", "total_net": "97100.00",
  "journal_entry_id": null, "payment_journal_entry_id": null, "payment_method": null, "paid_at": null,
  "approved_by": null, "approved_at": null, "notes": null,
  "created_by": { "id": "01m...", "name": "Rana" }, "created_at": "..."
}
```

Status flow: `draft → approved → paid`. `total_earned` is pay after attendance
deductions (the salaries expense), `total_withheld` = insurance + tax, `total_net` = what employees receive.

### `GET /api/v1/payroll-runs`

Requires `payroll-runs.view`. Search matches period and notes. `?export=csv|xlsx` needs `payroll-runs.export`.

### `GET /api/v1/payroll-runs/{id}` · `GET /api/v1/payroll-runs/{id}/payslips`

Requires `payroll-runs.view`. The payslips are a paginated list (search: employee code/name).

### `POST /api/v1/payroll-runs`

Requires `payroll-runs.create`. Calculates a month as a **draft** — nothing reaches
the ledger yet.

```json
{ "period": "2026-09", "notes": "September payroll" }
```

- **422** "Payroll can only be calculated once the month has ended — on its last day or later.";
- **422** on `period` if a run for that month already exists;
- **422** "No employees are on the payroll for 2026-09." when nobody was employed;
- employees included: hired on or before the month's end and (active, or terminated within/after the month);
- **201** — the run, with one payslip per employee.

### `PUT /api/v1/payroll-runs/{id}/recalculate`

Requires `payroll-runs.recalculate`. Recomputes a **draft** from attendance, leave and
permissions as they are now (after fixing an incomplete day, say). **422** "Only a draft payroll run can be recalculated."

### `POST /api/v1/payroll-runs/{id}/approve`

Requires `payroll-runs.approve`. Locks the run and **posts to the chart of accounts**:

```
Dr 6100  Salaries Expense                       total_earned
   Cr 2210  Payroll Taxes & Insurance Payable   total_withheld
   Cr 2200  Salaries Payable                    total_net
```

dated the last day of the period. **422** "Only a draft payroll run can be approved."

### `POST /api/v1/payroll-runs/{id}/pay`

Requires `payroll-runs.pay`.

```json
{ "payment_method": "bank", "payment_date": "2026-10-01" }
```

`payment_method`: `cash` (account 1110) or `bank` (1120); `payment_date` defaults to today.
Posts **Dr 2200 Salaries Payable / Cr Cash or Bank** for `total_net`. **422**
"Approve the payroll run before paying it." / "This payroll run has already been paid."

> The tax and insurance sitting in `2210` are paid to the authorities with a
> manual journal entry (`POST /journal-entries`: Dr 2210 / Cr Cash or Bank) —
> there is no dedicated endpoint for that yet.

### `DELETE /api/v1/payroll-runs/{id}`

Requires `payroll-runs.delete`. **204**, **drafts only** (**422** "Only a draft payroll
run can be deleted — an approved one has already posted to the ledger.").

### Automatic generation

`php artisan hr:generate-payroll` (scheduled nightly) creates the **draft** for every
company on the **last day of the month** and does nothing on other days; companies
that already have a run for the period are skipped. `--period=2026-09` generates a
specific past month. It only drafts — a person still approves and pays.

---

## Payslips

```json
{
  "id": "01m...", "payroll_run": { "id": "01m...", "period": "2026-09", "status": "approved" },
  "employee_id": "01m...", "employee_code": "E-014", "employee_name": "Mona Adel",
  "period_days": 30, "working_days": 22, "weekend_days": 8, "holiday_days": 0,
  "present_days": 20, "absent_days": 1, "paid_leave_days": 1, "unpaid_leave_days": 0,
  "incomplete_days": 0, "late_minutes": 55, "early_leave_minutes": 30,
  "shortfall_minutes": 85, "excused_minutes": 60, "unexcused_minutes": 10, "overtime_minutes": 0,
  "basic_salary": "9000.00", "allowances": "1000.00", "overtime_pay": "0.00",
  "gross_earnings": "10000.00", "absence_deduction": "333.33", "unpaid_leave_deduction": "0.00",
  "shortfall_deduction": "6.94", "attendance_deductions": "340.27", "earned_pay": "9659.73",
  "insurance": "990.00", "tax": "866.97", "net_pay": "7802.76"
}
```

A payslip is a snapshot — later salary or attendance changes don't alter it.

### `GET /api/v1/payslips` · `GET /api/v1/payslips/{id}`

Requires `payslips.view`.

- With `payroll-runs.view` (HR Manager, Accountant, Owner, Auditor): every payslip, drafts included.
- Without it (ordinary employee): **only their own**, and only once the run is **approved or paid** — a draft is still being checked.

`?export=csv|xlsx` on the list needs `payslips.export`. Search: employee code/name.

---

## Setting it up

1. `php artisan migrate`, then run `AccountSeeder` (new accounts 2200, 2210, 6100) and `RolePermissionSeeder` (new `HR Manager` / `Employee` roles and the HR permissions) and `HRDatabaseSeeder` (default schedule). Or `php artisan db:seed`.
2. Create departments, holidays and employees; **link each employee to their login** with `user_id`.
3. Give users the `Employee` role (self-service) or `HR Manager`.
4. Month end: payroll drafts itself (or `POST /payroll-runs`) → review payslips → `approve` → `pay`.
