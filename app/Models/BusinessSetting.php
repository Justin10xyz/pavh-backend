<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $place_of_issue
 * @property string|null $business_regime
 * @property string|null $tax_id
 * @property string|null $tax_id_number
 * @property string|null $address
 * @property string|null $neighborhood
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $phone_primary
 * @property string|null $phone_secondary
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|BusinessSetting newModelQuery()
 * @method static Builder<static>|BusinessSetting newQuery()
 * @method static Builder<static>|BusinessSetting query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'place_of_issue',
    'business_regime',
    'tax_id',
    'tax_id_number',
    'address',
    'neighborhood',
    'city',
    'postal_code',
    'phone_primary',
    'phone_secondary',
    'email',
])]
class BusinessSetting extends Model
{
    use SerializesDatesInAppTimezone;

    private const SINGLETON_ID = 1;

    /**
     * Registro único: cualquier alta se fuerza a id 1 (una segunda choca con
     * la llave primaria) y el borrado se cancela siempre.
     */
    protected static function booted(): void
    {
        static::creating(function (BusinessSetting $setting) {
            $setting->id = self::SINGLETON_ID;
        });

        static::deleting(fn () => false);
    }

    /**
     * El único registro, creado vacío la primera vez. insertOrIgnore y no
     * create(): dos primeras lecturas concurrentes no chocan con la llave.
     */
    public static function current(): self
    {
        if ($setting = static::find(self::SINGLETON_ID)) {
            return $setting;
        }

        static::query()->insertOrIgnore([
            'id' => self::SINGLETON_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return static::findOrFail(self::SINGLETON_ID);
    }
}
