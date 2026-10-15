<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Diplome;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Tout générer et télécharger le .rar": one request per student builds its
 * .docx and parks it in storage/app/diploma_batch/{batch}/{filiere}/, then a
 * final request packs the whole folder into a single .rar (WinRAR) served from
 * the public disk (storage:link) so the browser downloads it natively -- no
 * hundreds of MB streamed through axios. Files only: no Diplome row, no status
 * change. The frontend's progress bar counts the real per-student requests, so
 * the endpoints must answer one student at a time.
 *
 * Cleanup deletes by primary key: the suite shares a database.
 */
class DiplomeBatchArchiveTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdGroupes = [];
    private array $createdNiveaux = [];
    private array $createdFilieres = [];
    private array $batches = [];
    private array $createdZips = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'diplome.batch@test.local')->first()
            ?? User::create([
                'name'     => 'Diplome batch admin',
                'email'    => 'diplome.batch@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::first();

        $this->assertNotNull($this->annee, 'An academic year is required to test batch generation');
    }

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        foreach ($this->batches as $batch) {
            $dir = storage_path('app/diploma_batch/' . $batch);
            $fs->deleteDirectory($dir);
            $fs->delete($dir . '.rar');
        }
        foreach ($this->createdZips as $zip) {
            $fs->delete($zip);
        }

        foreach ($this->createdEtudiants as $etudiant) {
            Diplome::where('etudiant_id', $etudiant->id)->delete();
            Etudiant::where('id', $etudiant->id)->delete();
        }
        foreach ($this->createdGroupes as $groupe) {
            Groupe::where('id', $groupe->id)->delete();
        }
        foreach ($this->createdNiveaux as $niveau) {
            Niveau::where('id', $niveau->id)->delete();
        }
        foreach ($this->createdFilieres as $filiere) {
            Filiere::where('id', $filiere->id)->delete();
        }

        parent::tearDown();
    }

    private function makeGroupe(string $suffix): Groupe
    {
        $filiere = Filiere::create([
            'nom'           => 'FIL BATCH ' . $suffix,
            'code'          => 'FB' . substr(md5($suffix . uniqid()), 0, 8),
            'section'       => 'SECTION',
            'nombre_annees' => 1,
        ]);
        $this->createdFilieres[] = $filiere;

        $niveau = Niveau::create([
            'filiere_id' => $filiere->id,
            'numero'     => 1,
        ]);
        $this->createdNiveaux[] = $niveau;

        $groupe = Groupe::create([
            'niveau_id'           => $niveau->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                 => 'GRP BATCH ' . $suffix,
            'promotion'           => 'BATCH ' . $suffix,
        ]);
        $this->createdGroupes[] = $groupe;

        return $groupe;
    }

    private function makeEtudiant(string $suffix, int $groupeId): Etudiant
    {
        $etudiant = Etudiant::create([
            'user_id'             => $this->admin->id,
            'groupe_id'           => $groupeId,
            'annee_academique_id' => $this->annee->id,
            'nom_prenom'          => 'BATCH STUDENT ' . $suffix,
            'cin'                 => 'CIN' . substr(md5($suffix . uniqid('', true)), 0, 12),
            'numero_inscription'  => 'INS' . substr(md5('num' . $suffix . uniqid('', true)), 0, 14),
            'status'              => 'active',
        ]);

        $this->createdEtudiants[] = $etudiant;
        return $etudiant;
    }

    private function newBatch(): string
    {
        $batch = 'testbatch' . substr(md5(uniqid('', true)), 0, 10);
        $this->batches[] = $batch;
        return $batch;
    }

    public function test_batch_generate_builds_the_docx_by_filiere_without_touching_the_database(): void
    {
        $etudiant = $this->makeEtudiant('ONE', $this->makeGroupe('ONE')->id);
        $batch = $this->newBatch();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/diplomes/batch-generate', [
                'etudiant_id'         => $etudiant->id,
                'annee_academique_id' => $this->annee->id,
                'batch'               => $batch,
            ]);

        $res->assertOk();
        $this->assertTrue($res->json('success'));

        // Files only: no diploma row is recorded and the student's status stays.
        $this->assertDatabaseMissing('diplomes', [
            'etudiant_id'         => $etudiant->id,
            'annee_academique_id' => $this->annee->id,
        ]);
        $this->assertSame('active', $etudiant->refresh()->status);

        // One folder per filière inside the batch folder, diploma inside it.
        $files = glob(storage_path("app/diploma_batch/{$batch}/FIL_BATCH_ONE/*.docx"));
        $this->assertNotEmpty($files, 'The .docx must be parked under a folder named after the filière');
        $this->assertStringStartsWith('PK', (string) file_get_contents($files[0]));
    }

    public function test_batch_generate_rejects_an_unsafe_batch_identifier(): void
    {
        $etudiant = $this->makeEtudiant('SAFE', $this->makeGroupe('SAFE')->id);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/diplomes/batch-generate', [
                'etudiant_id'         => $etudiant->id,
                'annee_academique_id' => $this->annee->id,
                'batch'               => '../escape',
            ]);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('diplomes', ['etudiant_id' => $etudiant->id]);
    }

    public function test_batch_archive_packs_every_filiere_folder_into_one_rar(): void
    {
        $batch = $this->newBatch();
        foreach (['Filiere A', 'Filiere B'] as $folder) {
            $dir = storage_path("app/diploma_batch/{$batch}/{$folder}");
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/Diplome_X.docx', 'PK-fake-docx');
        }

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/diplomes/batch-archive', [
                'batch'               => $batch,
                'annee_academique_id' => $this->annee->id,
            ]);

        // Public URL answer (not a binary stream): the browser downloads the
        // finished archive natively. If Rar.exe is missing the controller
        // returns JSON 500.
        if ($res->getStatusCode() === 500 && str_contains((string) $res->json('message'), 'WinRAR')) {
            $this->markTestSkipped('WinRAR (Rar.exe) is not installed on this machine');
        }

        $res->assertOk();
        $filename = $res->json('data.filename');
        $path = $res->json('data.path');

        $this->assertStringContainsString('Diplomes_', (string) $filename);
        $this->assertStringContainsString('.rar', (string) $filename);
        $this->assertStringContainsString('/storage/diploma_zips/', (string) $path);

        // The archive is parked in the public disk as a real WinRAR file.
        $zip = storage_path('app/public/diploma_zips/' . basename((string) $filename));
        $this->createdZips[] = $zip;
        $this->assertFileExists($zip);
        $this->assertStringStartsWith('Rar!', (string) file_get_contents($zip));

        // The folder lives inside the archive now; the server drops it so a
        // re-run of the same list starts clean.
        $this->assertDirectoryDoesNotExist(storage_path('app/diploma_batch/' . $batch));
    }

    public function test_batch_archive_refuses_a_batch_with_no_diplomas(): void
    {
        $batch = $this->newBatch();
        mkdir(storage_path('app/diploma_batch/' . $batch), 0755, true);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/diplomes/batch-archive', ['batch' => $batch]);

        $res->assertStatus(400);
        $this->assertStringContainsString('Aucun diplôme', (string) $res->json('message'));
    }

    public function test_batch_download_serves_the_finished_archive_with_a_query_token(): void
    {
        $zipDir = storage_path('app/public/diploma_zips');
        @mkdir($zipDir, 0755, true);
        $filename = 'Diplomes_DOWNLOAD_TEST.rar';
        $zip = $zipDir . '/' . $filename;
        file_put_contents($zip, 'Rar!-fake-archive');
        $this->createdZips[] = $zip;

        $token = $this->admin->createToken('batch-download-test')->plainTextToken;

        // Public route: a real <a> cannot send an Authorization header, so the
        // plain-text token travels in the query string and is checked manually.
        $res = $this->get('/api/admin/diplomes/batch-download?filename=' . urlencode($filename) . '&token=' . urlencode($token));

        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/vnd.rar');
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString($filename, (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('Rar!-fake-archive', file_get_contents($res->getFile()->getPathname()));
    }

    public function test_batch_download_requires_an_admin_token_and_a_safe_filename(): void
    {
        $zipDir = storage_path('app/public/diploma_zips');
        @mkdir($zipDir, 0755, true);

        $bad = $this->get('/api/admin/diplomes/batch-download?filename=Diplomes_TEST.rar&token=garbage');
        $bad->assertStatus(401);

        $token = $this->admin->createToken('batch-download-test-2')->plainTextToken;
        $unsafe = $this->get('/api/admin/diplomes/batch-download?filename=..%2F..%2Fconfig&token=' . urlencode($token));
        $unsafe->assertStatus(404);

        $missing = $this->get('/api/admin/diplomes/batch-download?filename=Diplomes_Nope.rar&token=' . urlencode($token));
        $missing->assertStatus(404);
    }
}
