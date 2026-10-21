<?php

use App\Models\Complaint;
use App\Models\User;
use App\Services\AdminPageAccessService;
use Illuminate\Support\Facades\Route;

it('assigns a unique public user code when a learner registers', function () {
    $response = $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'Learner',
        'email' => 'learner.test@example.com',
        'username' => 'learner.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect(route('dashboard'));

    $user = User::where('email', 'learner.test@example.com')->firstOrFail();

    $this->assertNotNull($user->user_code);
    $this->assertMatchesRegularExpression('/^'.now()->format('y').'-LN-\d{4}$/', $user->user_code);
    $this->assertSame(1, User::where('user_code', $user->user_code)->count());
});

it('shows password requirements and rejects passwords that do not meet them', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('At least 8 characters')
        ->assertSee('At least 1 uppercase letter')
        ->assertSee('At least 1 lowercase letter')
        ->assertSee('At least 1 number')
        ->assertSee('At least 1 special character');

    $this->get(route('faculty.register'))
        ->assertOk()
        ->assertSee('At least 8 characters')
        ->assertSee('At least 1 uppercase letter')
        ->assertSee('At least 1 lowercase letter')
        ->assertSee('At least 1 number')
        ->assertSee('At least 1 special character');

    $this->post(route('register.store'), [
        'first_name' => 'Test',
        'last_name' => 'Learner',
        'email' => 'learner.weak@example.com',
        'username' => 'learner.weak',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertSessionHasErrors('password');

    $this->assertDatabaseMissing('users', ['email' => 'learner.weak@example.com']);
});

it('assigns a role-based user code when an admin creates a user', function () {
    $this->actingAs(User::factory()->admin()->create());

    $response = $this->post(route('admin.users.store'), [
        'first_name' => 'Test',
        'last_name' => 'Faculty',
        'email' => 'faculty.create@example.com',
        'username' => 'faculty.create',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role_id' => 2,
    ]);

    $response->assertRedirect();

    $user = User::where('email', 'faculty.create@example.com')->firstOrFail();

    $this->assertNotNull($user->user_code);
    $this->assertMatchesRegularExpression('/^'.now()->format('y').'-FC-\d{4}$/', $user->user_code);
    $this->assertSame(1, User::where('user_code', $user->user_code)->count());
});

it('lets a regular administrator create ordinary accounts without assigning admin roles', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.usermanagement', ['role' => 'administrators']))
        ->assertOk()
        ->assertSee('Create account')
        ->assertSee('name="role_id"', false)
        ->assertSee('Faculty')
        ->assertSee('Learner')
        ->assertDontSee('Superadmin')
        ->assertDontSee('Sub-admin');

    foreach ([
        User::ROLE_FACULTY => ['faculty', 'FC', 'faculty'],
        User::ROLE_STUDENT => ['learner', 'LN', 'students'],
    ] as $roleId => [$roleName, $codePrefix, $roleTab]) {
        $email = $roleName.'.created@example.com';

        $this->post(route('admin.users.store'), [
            'first_name' => 'Created',
            'last_name' => ucfirst($roleName),
            'email' => $email,
            'username' => $roleName.'.created',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_id' => $roleId,
        ])->assertRedirect(route('admin.usermanagement', ['role' => $roleTab]));

        $user = User::where('email', $email)->firstOrFail();

        expect((int) $user->role_id)->toBe($roleId)
            ->and($user->user_code)->toMatch('/^'.now()->format('y').'-'.$codePrefix.'-\d{4}$/');
    }

    foreach ([User::ROLE_ADMIN, User::ROLE_SUPERADMIN, User::ROLE_SUBADMIN] as $roleId) {
        $this->post(route('admin.users.store'), [
            'first_name' => 'Elevated',
            'last_name' => 'Account',
            'email' => 'elevated-'.$roleId.'@example.com',
            'username' => 'elevated.'.$roleId,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_id' => $roleId,
        ])->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => 'elevated-'.$roleId.'@example.com']);
    }
});

it('lets only superadmins create superadmins and page-restricted subadmins', function () {
    $superadmin = User::factory()->create(['role_id' => User::ROLE_SUPERADMIN]);

    $this->actingAs($superadmin)
        ->get(route('admin.usermanagement'))
        ->assertOk()
        ->assertSee('Superadmin')
        ->assertSee('Sub-admin')
        ->assertSee('name="admin_page_access[]"', false)
        ->assertDontSee('name="admin_page_access[]" value="dashboard"', false)
        ->assertDontSee('name="admin_page_access[]" value="profile"', false)
        ->assertSee('Courses');

    $this->post(route('admin.users.store'), [
        'first_name' => 'Limited',
        'last_name' => 'Admin',
        'email' => 'limited.admin@example.com',
        'username' => 'limited.admin',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role_id' => User::ROLE_SUBADMIN,
        'admin_page_access' => ['courses', 'reports'],
    ])->assertRedirect(route('admin.usermanagement', ['role' => 'administrators']));

    $subadmin = User::where('email', 'limited.admin@example.com')->firstOrFail();
    expect((int) $subadmin->role_id)->toBe(User::ROLE_SUBADMIN)
        ->and($subadmin->admin_page_access)->toBe(['dashboard', 'profile', 'courses', 'reports']);

    $this->post(route('admin.users.store'), [
        'first_name' => 'Baseline',
        'last_name' => 'Admin',
        'email' => 'baseline.admin@example.com',
        'username' => 'baseline.admin',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role_id' => User::ROLE_SUBADMIN,
    ])->assertRedirect(route('admin.usermanagement', ['role' => 'administrators']));

    expect(User::where('email', 'baseline.admin@example.com')->firstOrFail()->admin_page_access)
        ->toBe(['dashboard', 'profile']);

    $this->post(route('admin.users.store'), [
        'first_name' => 'Second',
        'last_name' => 'Superadmin',
        'email' => 'second.superadmin@example.com',
        'username' => 'second.superadmin',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role_id' => User::ROLE_SUPERADMIN,
    ])->assertRedirect(route('admin.usermanagement', ['role' => 'administrators']));

    expect(User::where('email', 'second.superadmin@example.com')->firstOrFail()->isSuperAdmin())->toBeTrue();
});

it('restricts subadmin navigation and requests to assigned admin pages', function () {
    $subadmin = User::factory()->create([
        'role_id' => User::ROLE_SUBADMIN,
        'admin_page_access' => ['courses'],
    ]);

    $this->actingAs($subadmin)
        ->get(route('admin.courses'))
        ->assertOk()
        ->assertSee('Courses')
        ->assertDontSee('User Management');

    $this->get(route('admin.report'))->assertForbidden();
    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Published courses')
        ->assertSee('Courses &amp; Badges', false)
        ->assertSee('Enrollment and completion by course')
        ->assertDontSee('Total learners')
        ->assertDontSee('User Management')
        ->assertDontSee('Live monitoring feed')
        ->assertDontSee('Recent badges')
        ->assertDontSee('Announcements');
    $this->get(route('monitoring.live'))->assertForbidden();
    $this->get(route('admin.profile'))->assertOk();

    $this->post(route('admin.users.store'), [
        'first_name' => 'Escalated',
        'last_name' => 'Account',
        'email' => 'escalated@example.com',
        'username' => 'escalated.account',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role_id' => User::ROLE_SUPERADMIN,
    ])->assertForbidden();

    $complaint = Complaint::create([
        'source' => 'visitor',
        'guest_name' => 'Visitor',
        'guest_email' => 'visitor@example.com',
        'subject' => 'Attachment access',
        'message' => 'Test attachment authorization.',
        'attachment_url' => 'uploads/complaints/private.pdf',
    ]);

    $this->get(route('complaints.attachment', $complaint->id))->assertForbidden();
});

it('maps all administrator routes to a page permission', function () {
    $adminPageAccess = app(AdminPageAccessService::class);
    $unmappedRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
        ->filter(fn ($route) => $adminPageAccess->pageForRoute($route->getName()) === null)
        ->map(fn ($route) => $route->getName())
        ->values();

    expect($unmappedRoutes)->toBeEmpty();
});

it('sends a subadmin to the default dashboard after login', function () {
    $subadmin = User::factory()->create([
        'role_id' => User::ROLE_SUBADMIN,
        'admin_page_access' => ['reports'],
    ]);

    $this->post(route('login.store'), [
        'email' => $subadmin->email,
        'password' => 'password',
    ])->assertRedirect(route('admin.dashboard'));
});

it('requires at least one valid page when a superadmin creates a subadmin', function () {
    $superadmin = User::factory()->create(['role_id' => User::ROLE_SUPERADMIN]);

    $this->actingAs($superadmin)->post(route('admin.users.store'), [
        'first_name' => 'No',
        'last_name' => 'Access',
        'email' => 'no.access@example.com',
        'username' => 'no.access',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'role_id' => User::ROLE_SUBADMIN,
        'admin_page_access' => ['not-a-page'],
    ])->assertSessionHasErrors('admin_page_access.0');

    $this->assertDatabaseMissing('users', ['email' => 'no.access@example.com']);
});

it('lets only superadmins update an existing subadmin page assignment', function () {
    $superadmin = User::factory()->create(['role_id' => User::ROLE_SUPERADMIN]);
    $subadmin = User::factory()->create([
        'role_id' => User::ROLE_SUBADMIN,
        'admin_page_access' => ['courses'],
    ]);

    $this->actingAs($superadmin)
        ->get(route('admin.usermanagement', ['role' => 'administrators']))
        ->assertOk()
        ->assertSee('Manage access')
        ->assertSee('<dialog class="manage-access-dialog"', false)
        ->assertSee('Overview')
        ->assertSee('Courses &amp; Credentials', false)
        ->assertDontSee('Assigned Pages')
        ->assertSee(route('admin.users.access.update', $subadmin->id));

    $this->patch(route('admin.users.access.update', $subadmin->id), [
        'page_access' => ['reports', 'complaints'],
    ])->assertRedirect(route('admin.usermanagement', ['role' => 'administrators']))
        ->assertSessionHas('success', 'Sub-admin access updated successfully.');

    expect($subadmin->fresh()->admin_page_access)
        ->toBe(['dashboard', 'profile', 'reports', 'complaints']);
});

it('denies non-superadmins and invalid assignments when managing subadmin access', function () {
    $subadmin = User::factory()->create([
        'role_id' => User::ROLE_SUBADMIN,
        'admin_page_access' => ['courses'],
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.users.access.update', $subadmin->id), [
            'page_access' => ['reports'],
        ])->assertForbidden();

    $superadmin = User::factory()->create(['role_id' => User::ROLE_SUPERADMIN]);

    $this->actingAs($superadmin)
        ->patch(route('admin.users.access.update', $subadmin->id), [
            'page_access' => [],
        ])->assertRedirect(route('admin.usermanagement', ['role' => 'administrators']));

    expect($subadmin->fresh()->admin_page_access)->toBe(['dashboard', 'profile']);

    $this->patch(route('admin.users.access.update', $subadmin->id), [
        'page_access' => ['not-a-page'],
    ])->assertSessionHasErrorsIn('access', 'page_access.0');

    expect($subadmin->fresh()->admin_page_access)->toBe(['dashboard', 'profile']);
});
