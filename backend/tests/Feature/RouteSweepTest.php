<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RouteSweepTest extends TestCase
{
    protected array $results = [];

    protected function makeUser(string $role): User
    {
        $email = "sweep.{$role}@sweep.test";
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name'     => "Sweep {$role}",
                'email'    => $email,
                'password' => Hash::make('secret123'),
                'role'     => $role,
            ]);
        }

        // Formateur screens read auth()->user()->formateur, so the sweep
        // account needs a linked Formateur record to reach the happy path.
        if ($role === 'formateur') {
            if (! $user->formateur) {
                DB::table('formateurs')->insert([
                    'user_id'    => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $user->refresh();
            }

            // sequenceEtudiants() enforces sequence ownership, so the sweep
            // account is granted the first sequence to exercise the happy path.
            $formateurId = $user->formateur->id;
            $sequenceId = DB::table('sequences')->value('id');

            if ($sequenceId !== null && ! DB::table('formateur_sequences')
                ->where('formateur_id', $formateurId)
                ->where('sequence_id', $sequenceId)
                ->exists()) {
                DB::table('formateur_sequences')->insert([
                    'formateur_id' => $formateurId,
                    'sequence_id' => $sequenceId,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        return $user;
    }

    /**
     * Replace {param} placeholders with real ids from the seeded database so
     * endpoints are exercised against plausible data instead of 404 noise.
     */
    /**
     * Replace {param} placeholders with real ids from the seeded database so
     * endpoints are exercised against plausible data instead of 404 noise.
     *
     * The table is chosen from the preceding path segment
     * (sequences/{id} -> sequences.id), because a bare {id} is ambiguous.
     */
    protected function resolveUri(string $uri): ?string
    {
        $segmentTables = [
            'etudiants'         => 'etudiants',
            'formateurs'        => 'formateurs',
            'groupes'           => 'groupes',
            'niveaux'           => 'niveaux',
            'filieres'          => 'filieres',
            'unites'            => 'unites',
            'sequences'         => 'sequences',
            'controles'         => 'controles',
            'annees-academiques'=> 'annees_academiques',
            'diplomes'          => 'diplomes',
            'evaluations'       => 'stage_evaluations',
        ];

        $ids = [];
        foreach ($segmentTables as $segment => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $value = DB::table($table)->value('id');
            if ($value !== null) {
                $ids[$segment] = (string) $value;
            }
        }

        $userId = Schema::hasTable('users') ? DB::table('users')->value('id') : null;

        $segments = explode('/', $uri);
        $previous = null;

        foreach ($segments as $i => $segment) {
            if (! preg_match('/^\{(\w+)\??\}$/', $segment, $m)) {
                $previous = $segment;
                continue;
            }

            $key = $previous !== null ? strtolower($previous) : strtolower($m[1]);

            if (isset($ids[$key])) {
                $segments[$i] = $ids[$key];
            } elseif ($userId !== null && str_contains($key, 'id')) {
                $segments[$i] = (string) $userId;
            } else {
                return null;
            }

            $previous = $segment;
        }

        return implode('/', $segments);
    }

    public function test_sweep_all_read_endpoints(): void
    {
        $admin      = $this->makeUser('admin');
        $formateur  = $this->makeUser('formateur');
        $etudiant   = $this->makeUser('etudiant');

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->filter(fn ($r) => !str_starts_with($r->uri(), '_') && !str_starts_with($r->uri(), 'sanctum') && !str_starts_with($r->uri(), 'api/doc'))
            ->filter(fn ($r) => !str_contains($r->uri(), 'storage'));

        $roles = ['admin' => $admin, 'formateur' => $formateur, 'etudiant' => $etudiant];
        $serverErrors = [];
        $summary = [];

        foreach ($roles as $roleName => $user) {
            foreach ($roles as $roleNameInner => $_) {
                // only iterate routes once, but per role
            }

            foreach ($routes as $route) {
                $uri = $this->resolveUri($route->uri());
                if ($uri === null) {
                    continue;
                }

                if (!str_starts_with($uri, 'api/')) {
                    continue;
                }

                $full = '/' . $uri;
                $response = $this->actingAs($user)->get($full, ['Accept' => 'application/json']);

                $status = $response->getStatusCode();
                $summary[$status] = ($summary[$status] ?? 0) + 1;

                if ($status >= 500) {
                    $detail = null;
                    try {
                        $this->withoutExceptionHandling()
                            ->actingAs($user)
                            ->get($full, ['Accept' => 'application/json']);
                    } catch (\Throwable $e) {
                        $detail = get_class($e) . ': ' . $e->getMessage();
                    }
                    $this->withExceptionHandling();

                    if ($detail === null) {
                        $body = $response->getContent();
                        $detail = 'response body: ' . substr($body, 0, 300);
                    }

                    $serverErrors[] = [
                        'role'   => $roleName,
                        'route'  => $full,
                        'name'   => $route->getName(),
                        'status' => $status,
                        'error'  => $detail,
                    ];
                }
            }
        }

        fwrite(STDERR, "\n=== SWEEP SUMMARY ===\n");
        ksort($summary);
        foreach ($summary as $status => $count) {
            fwrite(STDERR, sprintf("  HTTP %-4s : %d\n", $status, $count));
        }
        fwrite(STDERR, "=== 5xx ERRORS (" . count($serverErrors) . ") ===\n");
        foreach ($serverErrors as $e) {
            fwrite(STDERR, sprintf("\n  [%s] %s -> %s\n      %s\n", $e['role'], $e['route'], $e['status'], $e['error']));
        }

        $this->assertEmpty($serverErrors, 'Server errors found: ' . json_encode($serverErrors));
    }

    /**
     * Role boundaries are enforced by middleware tied to the route prefix
     * (admin/* -> IsAdmin, formateur/* -> IsFormateur, etudiant/* -> IsEtudiant).
     * This asserts both directions:
     *  - escalation: a role must never reach another role's area (expect 403)
     *  - over-blocking: the rightful role must never be refused (never 403)
     */
    public function test_role_boundaries_are_enforced(): void
    {
        $users = [
            'admin'     => $this->makeUser('admin'),
            'formateur' => $this->makeUser('formateur'),
            'etudiant'  => $this->makeUser('etudiant'),
        ];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
            ->filter(fn ($r) => !str_contains($r->uri(), 'storage'));

        $escalations = [];
        $overBlocks  = [];

        foreach ($routes as $route) {
            $uri = $this->resolveUri($route->uri());
            if ($uri === null) {
                continue;
            }

            $requiredRole = match (true) {
                str_starts_with($uri, 'api/admin/')     => 'admin',
                str_starts_with($uri, 'api/formateur/') => 'formateur',
                str_starts_with($uri, 'api/etudiant/')  => 'etudiant',
                default                                 => null,
            };

            if ($requiredRole === null) {
                continue;
            }

            $full = '/' . $uri;

            foreach ($users as $roleName => $user) {
                $status = $this->actingAs($user)->get($full, ['Accept' => 'application/json'])
                    ->getStatusCode();

                if ($roleName === $requiredRole) {
                    if ($status === 403) {
                        $overBlocks[] = "$roleName blocked from own route $full";
                    }
                } elseif ($status !== 403) {
                    $escalations[] = "$roleName reached $requiredRole route $full (HTTP $status)";
                }
            }
        }

        fwrite(STDERR, "\n=== ROLE BOUNDARIES ===\n");
        fwrite(STDERR, "  escalations : " . count($escalations) . "\n");
        fwrite(STDERR, "  over-blocks : " . count($overBlocks) . "\n");
        foreach (array_slice($escalations, 0, 20) as $e) {
            fwrite(STDERR, "  ESC  $e\n");
        }
        foreach (array_slice($overBlocks, 0, 20) as $e) {
            fwrite(STDERR, "  BLK  $e\n");
        }

        $this->assertEmpty($escalations, 'Privilege escalation: ' . implode('; ', $escalations));
        $this->assertEmpty($overBlocks, 'Over-blocking: ' . implode('; ', $overBlocks));
    }

    /**
     * Regression: a "formateur" role account with no linked Formateur record
     * used to dereference null and return HTTP 500 on every formateur screen.
     * It must be rejected as 403 with a readable message instead.
     */
    public function test_formateur_without_profile_is_rejected_cleanly(): void
    {
        // Must stay a truly unlinked account, and be re-runnable: the suite
        // runs against a persistent test database, not a refreshed one.
        User::where('email', 'sweep.orphan@sweep.test')->delete();

        $orphan = User::create([
            'name'     => 'Orphan Formateur',
            'email'    => 'sweep.orphan@sweep.test',
            'password' => Hash::make('secret123'),
            'role'     => 'formateur',
        ]);

        $this->assertNull($orphan->fresh()->formateur);

        $endpoints = [
            '/api/formateur/notes',
            '/api/formateur/sequences',
            '/api/formateur/scan-data',
            '/api/formateur/groupes',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->actingAs($orphan)->getJson($endpoint);

            $this->assertSame(403, $response->getStatusCode(), "$endpoint should be 403");
            $this->assertStringContainsString(
                'formateur',
                strtolower((string) ($response->json('message') ?? '')),
                "$endpoint should explain the missing profile"
            );
        }
    }
}
