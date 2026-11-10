<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Services\VariantCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VariantCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private VariantCodeGenerator $generator;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new VariantCodeGenerator;

        $category = Category::create(['name' => 'Floor', 'code_prefix' => 'PIS']);
        $unitType = UnitType::create(['name' => 'm2']);
        $supplier = Supplier::create(['name' => 'Interceramic']);

        $this->product = Product::create([
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'unit_type_id' => $unitType->id,
            'name' => 'Creato',
            'purchase_unit' => 'caja',
        ]);
    }

    private function saveVariant(string $code, string $color, string $size): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->product->id,
            'code' => $code,
            'color' => $color,
            'size' => $size,
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
        ]);
    }

    public function test_generates_code_with_normal_format(): void
    {
        $code = $this->generator->generate($this->product, 'Taupe', '60x120');

        $this->assertSame('PIS-CREATO-TAU-60X120', $code);
    }

    public function test_resolves_simple_collision_by_extending_color_to_four_letters(): void
    {
        $this->saveVariant('PIS-CREATO-GRA-60X120', 'Gray', '60X120');

        $code = $this->generator->generate($this->product, 'Graphite', '60x120');

        $this->assertSame('PIS-CREATO-GRAP-60X120', $code);
    }

    public function test_resolves_double_collision_with_incremental_digit(): void
    {
        $this->saveVariant('PIS-CREATO-GRA-60X120', 'Gray', '60X120');
        $this->saveVariant('PIS-CREATO-GRAY-60X120', 'Grayling', '60X120');

        $code = $this->generator->generate($this->product, 'Grayson', '60x120');

        $this->assertSame('PIS-CREATO-GRA2-60X120', $code);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function colorsWithAccentsProvider(): array
    {
        return [
            'ñ en la tercera letra' => ['Piñon', 'PIS-CREATO-PIN-60X120'],
            'vocal acentuada en la tercera letra' => ['Olíva', 'PIS-CREATO-OLI-60X120'],
            'ñ en la segunda letra' => ['Añil', 'PIS-CREATO-ANI-60X120'],
            'vocal acentuada en la primera letra' => ['Ámbar', 'PIS-CREATO-AMB-60X120'],
        ];
    }

    #[DataProvider('colorsWithAccentsProvider')]
    public function test_removes_accents_from_color_segment(string $color, string $expectedCode): void
    {
        $this->assertSame($expectedCode, $this->generator->generate($this->product, $color, '60x120'));
    }

    public function test_removes_accents_when_collision_extends_color_to_four_letters(): void
    {
        $this->saveVariant('PIS-CREATO-CAF-60X120', 'Cafetal', '60X120');

        $code = $this->generator->generate($this->product, 'Café', '60x120');

        $this->assertSame('PIS-CREATO-CAFE-60X120', $code);
    }

    public function test_fails_with_a_clear_message_when_the_category_has_no_prefix(): void
    {
        $this->product->category->update(['code_prefix' => null]);
        $this->product->refresh();

        try {
            $this->generator->generate($this->product, 'Taupe', '60x120');
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no tiene prefijo de código', $e->errors()['product_id'][0]);
        }
    }
}
