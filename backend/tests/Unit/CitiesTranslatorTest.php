<?php

namespace Tests\Unit;

use App\Services\CitiesTranslator;
use Tests\TestCase;

class CitiesTranslatorTest extends TestCase
{
    private CitiesTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new CitiesTranslator();
    }

    public function test_common_moroccan_cities_translate_to_arabic(): void
    {
        $this->assertSame('الدار البيضاء', $this->translator->translate('Casablanca'));
        $this->assertSame('الرباط', $this->translator->translate('RABAT'));
        $this->assertSame('فاس', $this->translator->translate('Fès'));
        $this->assertSame('مراكش', $this->translator->translate('Marrakech'));
    }

    public function test_accents_and_separators_are_normalised(): void
    {
        $this->assertSame('الجديدة', $this->translator->translate('EL JADIDA'));
        $this->assertSame('طانطان', $this->translator->translate('tan-tan'));
        $this->assertSame('الحسيمة', $this->translator->translate("El Hoceïma"));
        $this->assertSame('العرائش', $this->translator->translate("Larache"));
    }

    public function test_unknown_places_stay_empty(): void
    {
        $this->assertSame('', $this->translator->translate('Village Inconnu'));
        $this->assertSame('', $this->translator->translate(''));
        $this->assertSame('', $this->translator->translate(null));
    }
}