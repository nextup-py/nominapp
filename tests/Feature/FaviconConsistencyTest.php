<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('el favicon.ico de la raíz es el mismo que el de icons/ (el navegador lo pide cuando una página no declara favicon)', function () {
    expect(md5_file(public_path('favicon.ico')))->toBe(md5_file(public_path('icons/favicon.ico')));
});

it('toda vista HTML completa declara el favicon de marca', function () {
    $sinFavicon = collect(File::allFiles(resource_path('views')))
        ->map(fn (SplFileInfo $file) => $file->getPathname())
        // Los PDF los renderiza DomPDF: no hay pestaña de navegador que muestre un favicon.
        ->reject(fn (string $path) => str_contains($path, '/views/pdf/')
            || str_contains($path, '/views/vendor/')
            || str_ends_with($path, 'org-chart/pdf.blade.php'))
        ->filter(fn (string $path) => str_contains(file_get_contents($path), '<html'))
        ->reject(fn (string $path) => str_contains(file_get_contents($path), 'favicon-links'))
        ->map(fn (string $path) => str_replace(resource_path('views/'), '', $path))
        ->values()
        ->all();

    expect($sinFavicon)->toBe([]);
});

it('el organigrama declara el favicon nuevo', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $company = Company::factory()->create();

    $this->actingAs($user)
        ->get(route('org-chart.show', $company))
        ->assertOk()
        ->assertSee('icons/favicon.ico', false)
        ->assertSee('icons/favicon.svg', false);
});

it('el service worker no sirve los íconos de marca cache-first (un rebrand quedaría pegado en los dispositivos)', function () {
    $sw = file_get_contents(public_path('sw.js'));

    preg_match('/CACHE_FIRST_PATTERNS = \[(.*?)\];/s', $sw, $cacheFirst);
    preg_match('/NETWORK_FIRST_PATTERNS = \[(.*?)\];/s', $sw, $networkFirst);

    // En el JS el patrón es una regex literal, con las barras escapadas.
    expect($cacheFirst[1])->not->toContain('icons')
        ->and($networkFirst[1])->toContain('\\/icons\\/');
});
