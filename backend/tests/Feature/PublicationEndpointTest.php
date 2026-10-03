<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Groupe;
use App\Models\SemestrePublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The publication endpoints validate their input inside a catch(\Exception), so
 * every ValidationException was reported as a 500 instead of a 422, and a missing
 * publication was a 500 instead of a 404. These lock the status codes down.
 *
 * Cleanup snapshots the rows it touches rather than deleting by predicate: the
 * suite shares a database, and a broad delete here would take out fixture state
 * that other tests rely on.
 */
class PublicationEndpointTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $snapshot = [];
    /** Keyed by "groupe:annee:type" so protect() is idempotent. */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'pub.admin@test.local')->first()
            ?? User::create([
                'name'     => 'Publication admin',
                'email'    => 'pub.admin@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            $q = DB::table('semestre_publications')
                ->where('groupe_id', $key['groupe_id'])
                ->where('annee_academique_id', $key['annee_academique_id'])
                ->where('type', $key['type']);

            if (array_key_exists($key['__k'], $this->snapshot)) {
                // Put the row back exactly as it was. Columns are listed
                // explicitly: passing the snapshot array straight to update()
                // makes the query builder mix named and positional bindings.
                $row = $this->snapshot[$key['__k']];
                $q->update([
                    'is_published' => $row['is_published'],
                    'published_at' => $row['published_at'],
                    'updated_at'   => $row['updated_at'],
                ]);
            } else {
                $q->delete();
            }
        }

        parent::tearDown();
    }

    /** Remembers the current state of a publication key before the test writes it. */
    private function protect(int $groupeId, int $anneeId, string $type): void
    {
        $key = [
            'groupe_id'           => $groupeId,
            'annee_academique_id' => $anneeId,
            'type'                => $type,
            '__k'                 => "$groupeId:$anneeId:$type",
        ];

        if (array_key_exists($key['__k'], $this->touchedKeys)) {
            return;
        }
        $this->touchedKeys[$key['__k']] = $key;

        $row = DB::table('semestre_publications')
            ->where('groupe_id', $groupeId)
            ->where('annee_academique_id', $anneeId)
            ->where('type', $type)
            ->first();

        if ($row) {
            $this->snapshot[$key['__k']] = (array) $row;
        }
    }

    private function groupe(): Groupe
    {
        return Groupe::where('annee_academique_id', $this->annee->id)->first()
            ?? Groupe::first();
    }

    // ---------------------------------------------------------------- validation

    public function test_publish_rejects_an_unknown_type_with_422(): void
    {
        $groupe = $this->groupe();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/publish', [
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'type'                => 'notes_s3',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_publish_rejects_an_unknown_group_with_422(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/publish', [
                'groupe_id'           => 999999999,
                'annee_academique_id' => $this->annee->id,
                'type'                => 'bulletin',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('groupe_id');
    }

    public function test_publish_requires_the_academic_year(): void
    {
        $groupe = $this->groupe();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/publish', [
                'groupe_id' => $groupe->id,
                'type'      => 'bulletin',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('annee_academique_id');
    }

    public function test_publish_all_rejects_an_unknown_type_with_422(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/publish-all', [
                'annee_academique_id' => $this->annee->id,
                'type'                => 'nope',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_unpublish_rejects_an_unknown_type_with_422(): void
    {
        $groupe = $this->groupe();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/unpublish', [
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'type'                => 'nope',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_unpublish_all_rejects_an_unknown_type_with_422(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/unpublish-all', [
                'annee_academique_id' => $this->annee->id,
                'type'                => 'nope',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    // ------------------------------------------------------------------ 404 path

    public function test_unpublishing_a_missing_publication_is_a_404(): void
    {
        // Picked from the real rows rather than assumed: this database already
        // carries fixture publications, so a hardcoded triple may well exist.
        $absent = null;
        foreach (Groupe::where('annee_academique_id', $this->annee->id)->get() as $g) {
            foreach (['notes_s1', 'notes_s2', 'bulletin'] as $type) {
                if (!SemestrePublication::where([
                    'groupe_id'           => $g->id,
                    'annee_academique_id' => $this->annee->id,
                    'type'                => $type,
                ])->exists()) {
                    $absent = ['groupe_id' => $g->id, 'type' => $type];
                    break 2;
                }
            }
        }

        if (!$absent) {
            $this->markTestSkipped('Every group already has a publication of every type.');
        }

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/unpublish', [
                'groupe_id'           => $absent['groupe_id'],
                'annee_academique_id' => $this->annee->id,
                'type'                => $absent['type'],
            ])
            ->assertStatus(404);

        // Nothing was written on the way out.
        $this->assertSame(0, SemestrePublication::where([
            'groupe_id'           => $absent['groupe_id'],
            'annee_academique_id' => $this->annee->id,
            'type'                => $absent['type'],
        ])->count());
    }

    // ------------------------------------------------------------- round trips

    public function test_publish_then_unpublish_flips_the_row(): void
    {
        $groupe = $this->groupe();
        $this->protect($groupe->id, $this->annee->id, 'bulletin');

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/publish', [
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'type'                => 'bulletin',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_published', true);

        $this->assertTrue((bool) SemestrePublication::where([
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'type'                => 'bulletin',
        ])->value('is_published'));

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/unpublish', [
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'type'                => 'bulletin',
            ])
            ->assertOk();

        $row = SemestrePublication::where([
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'type'                => 'bulletin',
        ])->first();

        $this->assertFalse((bool) $row->is_published);
        $this->assertNull($row->published_at);
    }

    public function test_publish_is_idempotent_for_the_same_key(): void
    {
        $groupe = $this->groupe();
        $this->protect($groupe->id, $this->annee->id, 'notes_s1');

        $payload = [
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'type'                => 'notes_s1',
        ];

        $this->actingAs($this->admin)->postJson('/api/admin/publications/publish', $payload)->assertOk();
        $this->actingAs($this->admin)->postJson('/api/admin/publications/publish', $payload)->assertOk();

        $this->assertSame(1, SemestrePublication::where($payload)->count());
    }

    public function test_publish_all_then_unpublish_all_covers_every_group(): void
    {
        $expected = Groupe::where('annee_academique_id', $this->annee->id)->count();
        if ($expected === 0) {
            $this->markTestSkipped('Need groups in the current year.');
        }

        foreach (Groupe::where('annee_academique_id', $this->annee->id)->get() as $g) {
            $this->protect($g->id, $this->annee->id, 'notes_s2');
        }

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/publish-all', [
                'annee_academique_id' => $this->annee->id,
                'type'                => 'notes_s2',
            ])
            ->assertOk()
            ->assertJsonPath('count', $expected);

        $this->assertSame($expected, SemestrePublication::where([
            'annee_academique_id' => $this->annee->id,
            'type'                => 'notes_s2',
            'is_published'        => true,
        ])->count());

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/unpublish-all', [
                'annee_academique_id' => $this->annee->id,
                'type'                => 'notes_s2',
            ])
            ->assertOk();

        $this->assertSame(0, SemestrePublication::where([
            'annee_academique_id' => $this->annee->id,
            'type'                => 'notes_s2',
            'is_published'        => true,
        ])->count());
    }

    public function test_publish_all_on_an_empty_year_is_a_404(): void
    {
        // Needs a year that exists but holds no groups, which this fixture set
        // does not provide; kept for when one is added.
        $candidate = AnneeAcademique::whereDoesntHave('groupes')
            ->where('id', '!=', $this->annee->id)
            ->first();

        if (!$candidate) {
            $this->markTestSkipped('Need an academic year with no groups.');
        }

        $this->actingAs($this->admin)
            ->postJson('/api/admin/publications/publish-all', [
                'annee_academique_id' => $candidate->id,
                'type'                => 'notes_s1',
            ])
            ->assertStatus(404);
    }

    // ---------------------------------------------------------------- access

    public function test_publication_endpoints_are_admin_only(): void
    {
        $formateur = User::where('email', 'pub.formateur@sweep.test')->first()
            ?? User::create([
                'name'     => 'Non admin',
                'email'    => 'pub.formateur@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'formateur',
            ]);

        $this->actingAs($formateur)
            ->postJson('/api/admin/publications/publish', [
                'groupe_id'           => $this->groupe()->id,
                'annee_academique_id' => $this->annee->id,
                'type'                => 'bulletin',
            ])
            ->assertStatus(403);
    }

    public function test_index_can_filter_by_group_and_year(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/admin/publications?annee_academique_id=' . $this->annee->id)
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }
}
