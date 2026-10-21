<?php

namespace Tests\Unit\Services;

use App\Models\Comprobante;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Services\ComprobanteFolioGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComprobanteFolioGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private ComprobanteFolioGenerator $generator;

    private QuoteStatus $draftStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new ComprobanteFolioGenerator;
        $this->draftStatus = QuoteStatus::create(['name' => 'Borrador']);
    }

    private function createComprobante(string $folio, ?Quote $quote = null): Comprobante
    {
        $quote ??= Quote::create([
            'folio' => 'COT-'.$folio,
            'quote_status_id' => $this->draftStatus->id,
            'subtotal' => 100,
            'total' => 100,
        ]);

        return Comprobante::create([
            'folio' => $folio,
            'quote_id' => $quote->id,
            'date' => '2026-10-19',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    public function test_it_starts_at_cf_0001(): void
    {
        $this->assertSame('CF-0001', $this->generator->generate());
    }

    public function test_it_continues_from_the_highest_folio_including_soft_deleted(): void
    {
        $this->createComprobante('CF-0009');
        $this->createComprobante('CF-0010')->delete();

        $this->assertSame('CF-0011', $this->generator->generate());
    }

    public function test_a_quote_cannot_have_more_than_one_comprobante(): void
    {
        $quote = $this->createComprobante('CF-0001')->quote;

        $this->expectException(QueryException::class);

        $this->createComprobante('CF-0002', $quote);
    }
}
