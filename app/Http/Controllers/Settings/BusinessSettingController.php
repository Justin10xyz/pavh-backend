<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBusinessSettingRequest;
use App\Http\Resources\Settings\BusinessSettingResource;
use App\Models\BusinessSetting;

/**
 * Registro único sin id en la ruta: solo se lee y se actualiza, nunca se crea
 * ni se borra desde la API.
 */
class BusinessSettingController extends Controller
{
    public function show(): BusinessSettingResource
    {
        return new BusinessSettingResource(BusinessSetting::current());
    }

    public function update(UpdateBusinessSettingRequest $request): BusinessSettingResource
    {
        $setting = BusinessSetting::current();

        $setting->update($request->validated());

        return new BusinessSettingResource($setting);
    }
}
