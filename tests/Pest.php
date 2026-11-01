<?php

/**
 * Pest configuration for the add-on.
 *
 * Integration tests boot a minimal Statamic through Orchestra Testbench via
 * `Tests\TestCase`; Unit tests stay on PHPUnit's own base class and boot
 * nothing. Shared helpers and custom expectations go here when the suite
 * grows them.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Statamic\Auth\File\User as UserObject;
use Statamic\Auth\RoleRepository as FileRoleRepository;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Sites\Site as SiteObject;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

pest()->extend(TestCase::class)->in('Integration');

/**
 * A configured site by handle, for a test that needs the site object.
 *
 * @param  string  $handle  Handle of a site the test configured.
 * @return SiteObject The configured site with that handle.
 *
 * @throws RuntimeException When the test configured no site with that handle.
 */
function configuredSite(string $handle): SiteObject
{
    // Look up the site by its handle
    $site = Site::get($handle);
    // Refuse a handle the test never configured
    if (! $site instanceof SiteObject) {
        throw new RuntimeException("The test configured no [{$handle}] site.");
    }

    return $site;
}

/**
 * Make the install multisite with two sites, `en` at `/` (the default) and
 * `es` at `/es/`.
 *
 * Multisite needs the Pro edition, which the Control Panel checks on every
 * request.
 */
function useTwoSites(): void
{
    // Turn on the Pro edition and multisite
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
    // Configure the English and Spanish sites
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'es' => ['name' => 'Spanish', 'url' => 'http://localhost/es/', 'locale' => 'es_ES'],
    ]);
}

/**
 * Point form files at a directory of this test's own, so parallel workers
 * never share one.
 *
 * Remove it with removeFormsDirectory() after the test.
 *
 * @return string The directory form files now live in.
 */
function useTemporaryFormsDirectory(): string
{
    // Point the forms path at a unique temporary directory
    config(['statamic.forms.forms' => sys_get_temp_dir().'/msf-forms-'.uniqid('', true)]);

    return formsDirectory();
}

/**
 * The directory form files are read from and written to.
 *
 * @return string The configured forms directory.
 *
 * @throws RuntimeException When the configured value is not a path.
 */
function formsDirectory(): string
{
    // Read the configured forms path
    $directory = config('statamic.forms.forms');
    // Refuse a value that is not a path
    if (! is_string($directory)) {
        throw new RuntimeException('statamic.forms.forms is not a directory path.');
    }

    return $directory;
}

/**
 * Delete the forms directory a test pointed at.
 */
function removeFormsDirectory(): void
{
    File::deleteDirectory(formsDirectory());
}

/**
 * A super user, held in memory only, to sign in to the Control Panel as.
 *
 * @return UserObject An unsaved super user with the id `admin`.
 *
 * @throws RuntimeException When the users repository makes something other than a user.
 */
function superUser(): UserObject
{
    // Make a user and confirm the repository made a file user
    $user = User::make();
    if (! $user instanceof UserObject) {
        throw new RuntimeException('The users repository did not make a user.');
    }

    // Give the user an identity and super rights
    $user->id('admin');
    $user->email('admin@example.com');
    $user->makeSuper();

    return $user;
}

/**
 * Point user roles at a file of this test's own, so parallel workers never
 * share one.
 *
 * Pass the path it returns to `File::delete()` after the test.
 *
 * @return string Path of the roles file the repository now reads.
 *
 * @throws RuntimeException When the bound roles repository does not read a file.
 */
function useTemporaryRoles(): string
{
    // Build a unique roles file path
    $path = sys_get_temp_dir().'/msf-roles-'.uniqid('', true).'.yaml';

    // Confirm the bound roles repository reads a file
    $roles = Role::getFacadeRoot();
    if (! $roles instanceof FileRoleRepository) {
        throw new RuntimeException('The roles repository does not read a roles file.');
    }

    // Point the repository at that file
    $roles->path($path);

    return $path;
}

/**
 * A user held in memory with one role of its own carrying exactly these
 * permissions.
 *
 * The role is saved to the roles file useTemporaryRoles() pointed at. The id
 * keys core's permission cache, so give every user in a test its own.
 *
 * @param  string  $id  Id of both the user and its role, unique in the test.
 * @param  list<string>  $permissions  Permissions the user's role carries.
 * @return UserObject An unsaved user assigned the new role.
 *
 * @throws RuntimeException When the users repository makes something other than a user.
 */
function userWith(string $id, array $permissions): UserObject
{
    // Save a role named after the user with exactly these permissions
    $role = Role::make($id);
    $role->title($id);
    $role->permissions($permissions);
    $role->save();

    // Make a user and confirm the repository made a file user
    $user = User::make();
    if (! $user instanceof UserObject) {
        throw new RuntimeException('The users repository did not make a user.');
    }

    // Give the user an identity and assign it the role
    $user->id($id);
    $user->email("{$id}@example.com");
    $user->assignRole($id);

    return $user;
}
