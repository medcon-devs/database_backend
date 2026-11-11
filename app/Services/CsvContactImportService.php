<?php

namespace App\Services;

use App\Exceptions\CsvImportException;
use App\Models\CSVData;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CsvContactImportService
{
    private const WRITE_CHUNK_SIZE = 100;

    private const LOOKUP_CHUNK_SIZE = 500;

    private const REQUIRED_HEADERS = ['FIRST NAME', 'LAST NAME', 'EMAIL', 'COUNTRY', 'SPECIALITY'];

    private const ALLOWED_HEADERS = ['FIRST NAME', 'LAST NAME', 'EMAIL', 'PHONE', 'COUNTRY', 'SPECIALITY'];

    private const HEADER_ALIASES = [
        'FIRSTNAME' => 'FIRST NAME',
        'FIRST NAME' => 'FIRST NAME',
        'LASTNAME' => 'LAST NAME',
        'LAST NAME' => 'LAST NAME',
        'E MAIL' => 'EMAIL',
        'EMAIL' => 'EMAIL',
        'MOBILE' => 'PHONE',
        'MOBILE NUMBER' => 'PHONE',
        'PHONE NUMBER' => 'PHONE',
        'PHONE' => 'PHONE',
        'COUNTRY' => 'COUNTRY',
        'SPECIALTY' => 'SPECIALITY',
        'SPECIALITY' => 'SPECIALITY',
    ];

    private const MAX_REPORTED_ERRORS = 50;

    public function __construct(private ReferenceDataService $referenceData) {}

    public function import(UploadedFile $file, User $user): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw new CsvImportException('The uploaded CSV file could not be opened.', [], 400);
        }

        $format = $this->inspectFormat($path);
        $this->validateRows($path, $format);

        $currentRow = 1;

        try {
            [$contacts, $counts, $currentRow] = $this->readContacts($path, $format);

            $affectedEmails = DB::transaction(function () use ($contacts, $user, &$counts) {
                $existing = $this->existingContacts(array_column($contacts, 'email'));
                $timestamp = now();
                $writes = [];
                $histories = [];

                foreach ($contacts as $contact) {
                    $key = strtolower($contact['email']);
                    /** @var CSVData|null $existingContact */
                    $existingContact = $existing[$key] ?? null;

                    if ($existingContact === null) {
                        $writes[] = [
                            ...$contact,
                            'user_id' => $user->id,
                            'microsoft_tenant_id' => $user->microsoft_tenant_id,
                            'status' => 'active',
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                        $counts['rows_added']++;

                        continue;
                    }

                    $final = ['email' => $existingContact->email];
                    $changed = false;
                    foreach (['firstname', 'lastname', 'phone', 'country', 'speciality'] as $attribute) {
                        $incoming = $contact[$attribute];
                        $final[$attribute] = $incoming ?? $existingContact->{$attribute};
                        if ((string) $final[$attribute] !== (string) $existingContact->{$attribute}) {
                            $changed = true;
                        }
                    }

                    if (! $changed) {
                        $counts['rows_unchanged']++;

                        continue;
                    }

                    $writes[] = [
                        ...$final,
                        'user_id' => $existingContact->user_id,
                        'created_at' => $existingContact->created_at ?? $timestamp,
                        'updated_at' => $timestamp,
                    ];
                    $histories[] = [
                        'firstname' => $existingContact->firstname,
                        'lastname' => $existingContact->lastname,
                        'email' => $existingContact->email,
                        'phone' => $existingContact->phone,
                        'country' => $existingContact->country,
                        'speciality' => $existingContact->speciality,
                        'user_id' => $user->id,
                        'data_id' => $existingContact->id,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                    $counts['rows_updated']++;
                }

                $this->insertHistoriesAndActivities($histories, $user, $timestamp);

                foreach (array_chunk($writes, self::WRITE_CHUNK_SIZE) as $chunk) {
                    DB::table('csv_data')->upsert(
                        $chunk,
                        ['email'],
                        ['firstname', 'lastname', 'phone', 'country', 'speciality', 'updated_at']
                    );
                }

                return array_column($writes, 'email');
            });

            $this->syncSearchIndex($affectedEmails);

            return $counts;
        } catch (CsvImportException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            Log::error('CSV import database error.', [
                'row' => $currentRow,
                'sql_state' => $exception->errorInfo[0] ?? null,
                'driver_code' => $exception->errorInfo[1] ?? null,
            ]);

            throw new CsvImportException(
                "The database rejected CSV row {$currentRow}. No contacts were imported.",
                [[
                    'row' => $currentRow,
                    'column' => null,
                    'message' => 'The row contains a value that cannot be stored.',
                ]]
            );
        } catch (Throwable $exception) {
            Log::error('CSV import failed.', [
                'row' => $currentRow,
                'exception' => $exception::class,
            ]);

            throw new CsvImportException(
                'The CSV import could not be completed. No contacts were imported.',
                [['row' => $currentRow, 'column' => null, 'message' => 'Unexpected import failure.']],
                500
            );
        }
    }

    private function readContacts(string $path, array $format): array
    {
        $handle = $this->open($path);
        $rowNumber = 1;
        $contacts = [];
        $counts = [
            'rows_added' => 0,
            'rows_updated' => 0,
            'rows_unchanged' => 0,
            'rows_skipped' => 0,
            'rows_encoding_converted' => 0,
            'duplicate_rows_merged' => 0,
            'phone_values_ignored' => 0,
            'phone_ignored_rows' => [],
        ];

        try {
            $this->readCsvRow($handle, $format['delimiter']);

            while (($rawRow = $this->readCsvRow($handle, $format['delimiter'])) !== false) {
                $rowNumber++;
                if ($this->isBlankRow($rawRow)) {
                    continue;
                }

                $converted = false;
                $row = $this->mappedRow($rawRow, $format['headers'], $rowNumber, $converted);
                if ($converted) {
                    $counts['rows_encoding_converted']++;
                }

                if ($this->isScientificNumber($row['PHONE'] ?? '')) {
                    $row['PHONE'] = '';
                    $counts['phone_values_ignored']++;
                    if (count($counts['phone_ignored_rows']) < self::MAX_REPORTED_ERRORS) {
                        $counts['phone_ignored_rows'][] = $rowNumber;
                    }
                }

                if ($row['EMAIL'] === '') {
                    $counts['rows_skipped']++;

                    continue;
                }

                $key = strtolower($row['EMAIL']);
                if (isset($contacts[$key])) {
                    $counts['duplicate_rows_merged']++;
                } else {
                    $contacts[$key] = [
                        'firstname' => null,
                        'lastname' => null,
                        'email' => $row['EMAIL'],
                        'phone' => null,
                        'country' => null,
                        'speciality' => null,
                    ];
                }

                foreach ([
                    'firstname' => 'FIRST NAME',
                    'lastname' => 'LAST NAME',
                    'phone' => 'PHONE',
                    'country' => 'COUNTRY',
                    'speciality' => 'SPECIALITY',
                ] as $attribute => $column) {
                    if (($row[$column] ?? '') !== '') {
                        $contacts[$key][$attribute] = $row[$column];
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return [array_values($contacts), $counts, $rowNumber];
    }

    private function existingContacts(array $emails): array
    {
        $contacts = [];

        foreach (array_chunk($emails, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            CSVData::withTrashed()
                ->whereIn('email', $chunk)
                ->get()
                ->each(function (CSVData $contact) use (&$contacts) {
                    $contacts[strtolower($contact->email)] = $contact;
                });
        }

        return $contacts;
    }

    private function insertHistoriesAndActivities(array $histories, User $user, $timestamp): void
    {
        if ($histories === []) {
            return;
        }

        $firstNewHistoryId = ((int) DB::table('history_editing')->max('id')) + 1;
        foreach (array_chunk($histories, self::WRITE_CHUNK_SIZE) as $chunk) {
            DB::table('history_editing')->insert($chunk);
        }

        $dataIds = array_column($histories, 'data_id');
        $historyIds = DB::table('history_editing')
            ->where('id', '>=', $firstNewHistoryId)
            ->where('user_id', $user->id)
            ->whereIn('data_id', $dataIds)
            ->orderByDesc('id')
            ->get(['id', 'data_id'])
            ->unique('data_id')
            ->pluck('id', 'data_id');

        $activities = [];
        foreach ($dataIds as $dataId) {
            $activities[] = [
                'user_id' => $user->id,
                'data_id' => $dataId,
                'status' => 'update',
                'history_editing_id' => $historyIds[$dataId],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($activities, self::WRITE_CHUNK_SIZE) as $chunk) {
            DB::table('user_activity')->insert($chunk);
        }
    }

    private function syncSearchIndex(array $emails): void
    {
        if ($emails === []) {
            return;
        }

        try {
            foreach (array_chunk($emails, self::LOOKUP_CHUNK_SIZE) as $chunk) {
                CSVData::withTrashed()->whereIn('email', $chunk)->searchable();
            }
        } catch (Throwable $exception) {
            Log::warning('CSV contacts imported, but the search index could not be synchronized.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function inspectFormat(string $path): array
    {
        $handle = $this->open($path);

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false || trim($firstLine) === '') {
                throw new CsvImportException('The CSV file is empty.');
            }

            $delimiter = collect([',', ';', "\t"])
                ->mapWithKeys(fn (string $candidate) => [
                    $candidate => count(str_getcsv($firstLine, $candidate, '"', '')),
                ])
                ->sortDesc()
                ->keys()
                ->first();

            rewind($handle);
            $rawHeaders = $this->readCsvRow($handle, $delimiter);
            if ($rawHeaders === false) {
                throw new CsvImportException('The CSV header row could not be read.');
            }

            $headers = [];
            foreach ($rawHeaders as $index => $rawHeader) {
                $headers[] = $this->normalizeHeader($rawHeader, $index);
            }

            $missing = array_values(array_diff(self::REQUIRED_HEADERS, $headers));
            $unexpected = array_values(array_diff($headers, self::ALLOWED_HEADERS));
            $duplicates = array_keys(array_filter(array_count_values($headers), fn (int $count) => $count > 1));

            if ($missing !== [] || $unexpected !== [] || $duplicates !== []) {
                $messages = [];
                if ($missing !== []) {
                    $messages[] = 'Missing columns: '.implode(', ', $missing).'.';
                }
                if ($unexpected !== []) {
                    $messages[] = 'Unknown columns: '.implode(', ', $unexpected).'.';
                }
                if ($duplicates !== []) {
                    $messages[] = 'Duplicate columns: '.implode(', ', $duplicates).'.';
                }

                throw new CsvImportException(implode(' ', $messages), [[
                    'row' => 1,
                    'column' => null,
                    'message' => 'Required columns are FIRST NAME, LAST NAME, EMAIL, COUNTRY, SPECIALITY. PHONE is optional.',
                ]]);
            }

            return ['delimiter' => $delimiter, 'headers' => $headers];
        } finally {
            fclose($handle);
        }
    }

    private function validateRows(string $path, array $format): void
    {
        $handle = $this->open($path);
        $errors = [];
        $totalErrors = 0;
        $rowNumber = 1;

        try {
            $this->readCsvRow($handle, $format['delimiter']);

            while (($rawRow = $this->readCsvRow($handle, $format['delimiter'])) !== false) {
                $rowNumber++;
                if ($this->isBlankRow($rawRow)) {
                    continue;
                }

                if (count($rawRow) !== count($format['headers'])) {
                    $this->addError($errors, $totalErrors, $rowNumber, null,
                        'Expected '.count($format['headers']).' columns but found '.count($rawRow).'.');
                    continue;
                }

                try {
                    $converted = false;
                    $row = $this->mappedRow($rawRow, $format['headers'], $rowNumber, $converted);
                } catch (CsvImportException $exception) {
                    foreach ($exception->errors() as $error) {
                        $this->addError(
                            $errors,
                            $totalErrors,
                            $error['row'] ?? $rowNumber,
                            $error['column'] ?? null,
                            $error['message'] ?? $exception->getMessage()
                        );
                    }
                    continue;
                }

                foreach ($row as $column => $value) {
                    if (mb_strlen($value) > 255) {
                        $this->addError($errors, $totalErrors, $rowNumber, $column,
                            'The value exceeds the maximum length of 255 characters.');
                    }
                }

                if ($row['EMAIL'] !== '' && filter_var($row['EMAIL'], FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($errors, $totalErrors, $rowNumber, 'EMAIL', 'Invalid email address.');
                }

                if (
                    $row['COUNTRY'] !== ''
                    && $row['SPECIALITY'] !== ''
                    && $this->referenceData->normalizeCountry($row['COUNTRY']) === null
                    && $this->referenceData->normalizeCountry($row['SPECIALITY']) !== null
                ) {
                    $this->addError($errors, $totalErrors, $rowNumber, 'COUNTRY',
                        'COUNTRY and SPECIALITY appear to be swapped.');
                }
            }
        } finally {
            fclose($handle);
        }

        if ($totalErrors > 0) {
            throw new CsvImportException(
                'CSV validation failed. Fix the reported rows and upload the file again.',
                $errors,
                422,
                $totalErrors
            );
        }
    }

    private function mappedRow(array $rawRow, array $headers, int $rowNumber, bool &$converted): array
    {
        $mapped = array_fill_keys(self::ALLOWED_HEADERS, '');

        foreach ($headers as $index => $header) {
            $mapped[$header] = $this->normalizeCell((string) ($rawRow[$index] ?? ''), $rowNumber, $header, $converted);
        }

        return $mapped;
    }

    private function normalizeHeader(string $value, int $index): string
    {
        $converted = false;
        $value = $this->normalizeCell($value, 1, 'HEADER '.($index + 1), $converted);
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        $key = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? $value));

        return self::HEADER_ALIASES[$key] ?? $key;
    }

    private function normalizeCell(
        string $value,
        int $row,
        string $column,
        bool &$converted
    ): string {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        if (! mb_check_encoding($value, 'UTF-8')) {
            $normalized = @iconv('Windows-1252', 'UTF-8', $value);
            if ($normalized === false || ! mb_check_encoding($normalized, 'UTF-8')) {
                $normalized = @iconv('ISO-8859-1', 'UTF-8', $value);
            }

            if ($normalized === false || ! mb_check_encoding($normalized, 'UTF-8')) {
                throw new CsvImportException('CSV encoding validation failed.', [[
                    'row' => $row,
                    'column' => $column,
                    'message' => 'The value is not valid UTF-8 and could not be converted.',
                ]]);
            }

            $value = $normalized;
            $converted = true;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', $value)) {
            throw new CsvImportException('CSV contains unsupported control characters.', [[
                'row' => $row,
                'column' => $column,
                'message' => 'The value contains unsupported binary/control characters.',
            ]]);
        }

        return trim($value);
    }

    private function readCsvRow($handle, string $delimiter): array|false
    {
        return fgetcsv($handle, 0, $delimiter, '"', '');
    }

    private function open(string $path)
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new CsvImportException('The uploaded CSV file could not be opened.', [], 400);
        }

        return $handle;
    }

    private function isBlankRow(array $row): bool
    {
        return count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0;
    }

    private function isScientificNumber(string $value): bool
    {
        return $value !== ''
            && preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)[Ee][+-]?\d+$/', $value) === 1;
    }

    private function addError(
        array &$errors,
        int &$totalErrors,
        int $row,
        ?string $column,
        string $message
    ): void {
        $totalErrors++;
        if (count($errors) < self::MAX_REPORTED_ERRORS) {
            $errors[] = compact('row', 'column', 'message');
        }
    }
}
