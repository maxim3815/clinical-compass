<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('audit log rows can not be changed or deleted, even bypassing the application', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();
    $record = AuditLog::record($doctor, 'patient.sex_changed', $patient, $patient->patientProfile()->firstOrFail(), ['sex' => null], ['sex' => 'female']);

    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $record->id)->update(['action' => 'patient.forged'])))
        ->toThrow(QueryException::class, 'audit_log is append-only: UPDATE is not allowed')
        ->and(fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $record->id)->delete()))
        ->toThrow(QueryException::class, 'audit_log is append-only: DELETE is not allowed');

    expect(AuditLog::query()->sole()->action)->toBe('patient.sex_changed')
        ->and(AuditLog::query()->sole()->after)->toBe(['sex' => 'female']);
});
