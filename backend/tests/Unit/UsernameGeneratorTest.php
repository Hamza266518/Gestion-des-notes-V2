<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\UsernameGenerator;
use Tests\TestCase;

class UsernameGeneratorTest extends TestCase
{
    private array $createdIds = [];

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            User::where('id', $id)->delete();
        }

        $this->createdIds = [];

        parent::tearDown();
    }

    private function track(User $user): User
    {
        $this->createdIds[] = $user->id;

        return $user;
    }

    /**
     * @dataProvider nameCases
     */
    public function test_it_builds_a_name_dot_surname_identifier(string $name, string $expected): void
    {
        $this->assertSame($expected, app(UsernameGenerator::class)->baseFromName($name));
    }

    public static function nameCases(): array
    {
        return [
            'prenom nom'            => ['Mohammed Alami', 'mohammed.alami'],
            'tout en majuscules'    => ['FATIMA ZAHRA IDRISSI', 'fatima.zahra.idrissi'],
            'prefixe honorifique'   => ['Mme. Fatima-Zahra TAHRI', 'fatima.zahra.tahri'],
            'prefixe abrege'        => ['M. Anass KAICHOUH', 'anass.kaichouh'],
            'accents'               => ['Rachid El Amrani', 'rachid.el.amrani'],
            'apostrophe'            => ["L night's El Hamel", 'l.night.s.el.hamel'],
            'nom vide'              => ['   ', UsernameGenerator::FALLBACK],
            'ponctuation seule'     => ['--- ...', UsernameGenerator::FALLBACK],
        ];
    }

    public function test_the_admin_identifier_is_based_on_admin(): void
    {
        // "admin" may already be taken by a seeded account, so a numeric suffix is expected.
        $this->assertMatchesRegularExpression(
            '/^admin\d*$/',
            app(UsernameGenerator::class)->reserveFor('N\'importe Quel Nom', 'admin')
        );
    }

    public function test_colliding_names_receive_a_numeric_suffix(): void
    {
        $generator = app(UsernameGenerator::class);

        $first = $this->track(User::create([
            'name' => 'ZZ Salima Benali', 'username' => $generator->reserveFor('ZZ Salima Benali', 'etudiant'),
            'password' => 'x', 'role' => 'etudiant',
        ]));
        $second = $this->track(User::create([
            'name' => 'ZZ Salima Benali', 'username' => $generator->reserveFor('ZZ Salima Benali', 'etudiant'),
            'password' => 'x', 'role' => 'etudiant',
        ]));

        $this->assertSame('zz.salima.benali', $first->username);
        $this->assertSame('zz.salima.benali2', $second->username);
    }

    public function test_reserving_the_same_identifier_for_the_same_user_is_stable(): void
    {
        $generator = app(UsernameGenerator::class);

        $user = $this->track(User::create([
            'name' => 'ZZ Karim Tazi', 'username' => $generator->reserveFor('ZZ Karim Tazi', 'etudiant'),
            'password' => 'x', 'role' => 'etudiant',
        ]));

        $this->assertSame($user->username, $generator->reserve($user->username, $user->id));
    }

    public function test_it_generates_a_username_when_none_is_supplied(): void
    {
        $user = $this->track(User::create(['name' => 'Hanane Bennani', 'password' => 'x', 'role' => 'etudiant']));

        $this->assertSame('hanane.bennani', $user->username);
    }

    public function test_it_never_overwrites_an_explicit_username(): void
    {
        $user = $this->track(User::create(['name' => 'Hanane Bennani', 'username' => 'hbennani', 'password' => 'x', 'role' => 'etudiant']));

        $this->assertSame('hbennani', $user->username);
    }

    public function test_format_validation(): void
    {
        $generator = app(UsernameGenerator::class);

        $this->assertTrue($generator->isValidFormat('m.alami'));
        $this->assertTrue($generator->isValidFormat('admin'));
        $this->assertFalse($generator->isValidFormat('M.Alami'));
        $this->assertFalse($generator->isValidFormat('m..alami'));
        $this->assertFalse($generator->isValidFormat('m alami'));
        $this->assertFalse($generator->isValidFormat('m@ifp.ma'));
        $this->assertFalse($generator->isValidFormat(''));
        $this->assertFalse($generator->isValidFormat(null));
    }
}