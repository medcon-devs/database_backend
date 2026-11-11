<?php

namespace App\Services;

use App\Models\CSVData;
use Illuminate\Support\Facades\DB;

class WhatsAppPhoneService
{
    public function normalize(string $phone): string
    {
        $phone = trim($phone);
        $hasInternationalPrefix = str_starts_with($phone, '+') || str_starts_with($phone, '00');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $defaultCountryCode = trim((string) config('services.whatsapp.default_country_code', '971'));
        if (
            ! $hasInternationalPrefix
            && $digits !== ''
            && $defaultCountryCode !== ''
            && ! str_starts_with($digits, $defaultCountryCode)
            && strlen($digits) <= 10
        ) {
            $digits = ltrim($digits, '0');
            $digits = $defaultCountryCode.$digits;
        }

        return $digits;
    }

    public function isValid(string $phone): bool
    {
        $length = strlen($this->normalize($phone));

        return $length >= 8 && $length <= 15;
    }

    public function findContactId(string $phone): ?int
    {
        $normalized = $this->normalize($phone);
        if ($normalized === '') {
            return null;
        }

        $expression = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', ''), '/', '')";

        $candidates = [$normalized];
        $defaultCountryCode = trim((string) config('services.whatsapp.default_country_code', '971'));
        if ($defaultCountryCode !== '' && str_starts_with($normalized, $defaultCountryCode)) {
            $local = substr($normalized, strlen($defaultCountryCode));
            if ($local !== '') {
                $candidates[] = $local;
                $candidates[] = '0'.$local;
            }
        }

        return CSVData::withTrashed()
            ->whereNotNull('phone')
            ->whereIn(DB::raw($expression), array_values(array_unique($candidates)))
            ->value('id');
    }
}
