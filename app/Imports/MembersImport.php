<?php

namespace App\Imports;

use App\Models\Member;
use App\Models\MemberType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\{
    ToModel,
    WithHeadingRow,
    WithValidation,
    SkipsOnFailure,
    SkipsFailures,
    WithChunkReading,
    WithUpserts
};

class MembersImport implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    SkipsOnFailure,
    WithChunkReading,
    ShouldQueue,
    WithUpserts
{
    use SkipsFailures;

    private $userId;
    private $processedEmails = [];
    private $processedMembershipNos = [];
    private $memberTypes = [];

    public function __construct($userId)
    {
        $this->userId = $userId;
        $this->memberTypes = MemberType::all()->keyBy(function ($type) {
            return strtolower($type->title);
        })->toArray();
    }

    public function chunkSize(): int
    {
        return 200;
    }

    public function prepareForValidation($data, $index)
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $value = preg_replace('/\x{00A0}/u', ' ', $value);
                $value = preg_replace('/\s+/', ' ', $value);
                $data[$key] = trim($value);
            }
        }
        if (!empty($data['email'])) {
            $emails = preg_split('/[\r\n,;]+/', $data['email']);
            $validEmails = [];
            foreach ($emails as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validEmails[] = strtolower($email);
                }
            }
            $data['email'] = !empty($validEmails) ? $validEmails[0] : null;
        }

        return $data;
    }

    public function model(array $row)
    {
        $membershipNo = $this->clean($row['membership_no'] ?? null);
        $name         = $this->clean($row['name'] ?? null);
        $email        = $this->clean($row['email'] ?? null);
        $state        = $this->clean($row['state'] ?? null);
        $city         = $this->clean($row['city_name'] ?? null);
        $mobile       = $this->clean($row['mobile_no'] ?? null);
        $membership   = $this->clean($row['membership_type'] ?? null);
        if ($membershipNo) {
            $membershipNo = str_replace(' ', '', $membershipNo);
        }
        if (empty($name)) {
            Log::warning("Name is required, skipping row");
            return null;
        }
        if ($email && in_array($email, $this->processedEmails)) {
            Log::warning("Duplicate email in same batch skipped: $email");
            return null;
        }

        if ($membershipNo && in_array($membershipNo, $this->processedMembershipNos)) {
            Log::warning("Duplicate membership no in same batch skipped: $membershipNo");
            return null;
        }
        if ($email && Member::where('email', $email)->exists()) {
            Log::warning("Duplicate email already exists: $email");
            return null;
        }

        if ($membershipNo && Member::where('membership_no', $membershipNo)->exists()) {
            Log::warning("Duplicate membership no already exists: $membershipNo");
            return null;
        }
        $password = Hash::make(Str::random(12));
        $memberType = $this->getOrCreateMemberType($membership);
        $memberData = [
            'name' => $name,
            'password' => $password,
            'city_name' => $city,
            'state' => $state,
            'mobile_no' => $mobile,
            'membership_type_id' => $memberType?->id,
            'membership_approved_date' => now(),
            'status' => 'approved',
            'user_id' => $this->userId,
            'is_active' => true,
            'is_verified' => true,
        ];
        if ($membershipNo) {
            $memberData['membership_no'] = $membershipNo;
            $memberData['email'] = $email ?? $membershipNo . '@temp.local';
        } else {
            $memberData['membership_no'] = null;
            $memberData['email'] = $email;
        }
        if ($email) {
            $this->processedEmails[] = $email;
        }
        if ($membershipNo) {
            $this->processedMembershipNos[] = $membershipNo;
        }

        return new Member($memberData);
    }

    private function getOrCreateMemberType($membership)
    {
        if (empty($membership)) {
            return null;
        }
        $key = strtolower($membership);
        if (isset($this->memberTypes[$key])) {
            if (is_array($this->memberTypes[$key])) {
                return MemberType::find($this->memberTypes[$key]['id']);
            }
            return $this->memberTypes[$key];
        }
        $type = MemberType::whereRaw('LOWER(title) = ?', [$key])->first();
        if (!$type) {
            $type = MemberType::create([
                'title' => $membership,
                'slug' => Str::slug($membership),
                'status' => 1
            ]);
        }
        $this->memberTypes[$key] = $type;

        return $type;
    }

    public function rules(): array
    {
        return [
            '*.membership_no' => ['nullable', 'string', 'max:255'],
            '*.name' => ['required', 'string', 'max:255'],
            '*.email' => ['nullable', 'email', 'max:255'],
            '*.mobile_no' => ['nullable', 'string', 'max:20'],
            '*.state' => ['nullable', 'string', 'max:100'],
            '*.city_name' => ['nullable', 'string', 'max:100'],
            '*.membership_type' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            '*.name.required' => 'Name is required for row :row',
            '*.email.email' => 'Invalid email format for row :row',
            '*.membership_no.max' => 'Membership number is too long for row :row',
            '*.name.max' => 'Name is too long for row :row',
        ];
    }

    public function onFailure(...$failures)
    {
        foreach ($failures as $failure) {
            Log::error('Import Error', [
                'row' => $failure->row(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ]);
        }
    }

    private function clean($value)
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        $value = preg_replace('/\x{00A0}/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $lower = strtolower($value);
        $invalidValues = ['nan', 'null', 'none', 'n/a', '#n/a', '-'];

        if (in_array($lower, $invalidValues)) {
            return null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * Define which columns should be used for upsert
     */
    public function uniqueBy()
    {
        return ['membership_no'];
    }
}
