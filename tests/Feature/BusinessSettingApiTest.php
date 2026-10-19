<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_get_creates_the_empty_record_the_first_time(): void
    {
        $this->assertDatabaseCount('business_settings', 0);

        $this->getJson('/api/business-settings')
            ->assertOk()
            ->assertJsonPath('data.place_of_issue', null)
            ->assertJsonPath('data.business_regime', null)
            ->assertJsonPath('data.tax_id', null)
            ->assertJsonPath('data.tax_id_number', null)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.neighborhood', null)
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.postal_code', null)
            ->assertJsonPath('data.phone_primary', null)
            ->assertJsonPath('data.phone_secondary', null)
            ->assertJsonPath('data.email', null);

        // Una segunda lectura no crea otro registro.
        $this->getJson('/api/business-settings')->assertOk();

        $this->assertDatabaseCount('business_settings', 1);
        $this->assertDatabaseHas('business_settings', ['id' => 1]);
    }

    public function test_put_updates_and_persists_the_settings(): void
    {
        $payload = [
            'place_of_issue' => 'Aguascalientes, Ags.',
            'business_regime' => 'Régimen de Actividad Empresarial',
            'tax_id' => 'XAXX010101000',
            'tax_id_number' => '12345678901',
            'address' => 'Av. Hidalgo 123',
            'neighborhood' => 'Centro',
            'city' => 'Villa Hidalgo, Jal.',
            'postal_code' => '47250',
            'phone_primary' => '495 123 4567',
            'phone_secondary' => '495 765 4321',
            'email' => 'ventas@innova.mx',
        ];

        $this->putJson('/api/business-settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.place_of_issue', 'Aguascalientes, Ags.')
            ->assertJsonPath('data.tax_id', 'XAXX010101000');

        $this->getJson('/api/business-settings')
            ->assertOk()
            ->assertJson(['data' => $payload]);

        $this->assertDatabaseHas('business_settings', ['id' => 1, ...$payload]);

        // Se puede volver a dejar un campo vacío.
        $this->putJson('/api/business-settings', [...$payload, 'tax_id_number' => null])
            ->assertOk()
            ->assertJsonPath('data.tax_id_number', null);

        $this->assertDatabaseCount('business_settings', 1);
    }

    public function test_put_validates_field_types(): void
    {
        $this->putJson('/api/business-settings', [
            'place_of_issue' => 123,
            'business_regime' => ['Régimen'],
            'tax_id' => true,
            'tax_id_number' => str_repeat('1', 256),
            'address' => 123,
            'neighborhood' => ['Centro'],
            'city' => false,
            'postal_code' => 47250,
            'phone_primary' => ['495'],
            'phone_secondary' => str_repeat('1', 256),
            'email' => 123,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'place_of_issue', 'business_regime', 'tax_id', 'tax_id_number',
                'address', 'neighborhood', 'city', 'postal_code', 'phone_primary', 'phone_secondary', 'email',
            ]);
    }

    public function test_put_keeps_omitted_contact_fields_and_clears_explicit_nulls(): void
    {
        $this->putJson('/api/business-settings', [
            'address' => 'Av. Hidalgo 123',
            'city' => 'Villa Hidalgo, Jal.',
            'phone_primary' => '495 123 4567',
            'email' => 'ventas@innova.mx',
        ])->assertOk();

        // Solo llega phone_primary (como null) y city: lo demás se queda.
        $this->putJson('/api/business-settings', [
            'city' => 'Aguascalientes, Ags.',
            'phone_primary' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.address', 'Av. Hidalgo 123')
            ->assertJsonPath('data.city', 'Aguascalientes, Ags.')
            ->assertJsonPath('data.phone_primary', null)
            ->assertJsonPath('data.email', 'ventas@innova.mx');

        $this->assertDatabaseHas('business_settings', [
            'id' => 1,
            'address' => 'Av. Hidalgo 123',
            'city' => 'Aguascalientes, Ags.',
            'phone_primary' => null,
            'email' => 'ventas@innova.mx',
        ]);
    }

    public function test_the_record_is_a_singleton_that_cannot_be_deleted(): void
    {
        $setting = BusinessSetting::current();

        $this->assertSame(1, $setting->id);
        $this->assertFalse($setting->delete());
        $this->assertDatabaseHas('business_settings', ['id' => 1]);

        $this->expectException(QueryException::class);
        BusinessSetting::create(['place_of_issue' => 'Otro']);
    }

    public function test_there_are_no_create_or_delete_endpoints(): void
    {
        $this->postJson('/api/business-settings', [])->assertMethodNotAllowed();
        $this->deleteJson('/api/business-settings')->assertMethodNotAllowed();
    }
}
