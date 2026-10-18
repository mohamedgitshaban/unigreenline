<?php

namespace Modules\Core\Tests\Feature\Permissions;

use Tests\TestCase;

class PermissionDocsTest extends TestCase
{
    public function test_the_permission_reference_is_up_to_date_with_the_routes_and_roles(): void
    {
        $path = 'storage/framework/testing/permissions-docs.md';
        @mkdir(base_path(dirname($path)), 0777, true);

        $this->artisan('permissions:docs', ['--path' => $path])->assertSuccessful();

        $this->assertSame(
            file_get_contents(base_path($path)),
            file_get_contents(base_path('docs/api/permissions.md')),
            'docs/api/permissions.md is out of date — run `php artisan permissions:docs`.',
        );

        @unlink(base_path($path));
    }
}
