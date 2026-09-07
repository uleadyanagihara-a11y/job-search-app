<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_can_insert_select_update_and_delete_verification_data(): void
    {
        // INSERT
        $user = User::query()->create([
            'name' => 'CRUD Verification Before Update',
            'email' => 'crud-verification@example.test',
            'password' => 'verification-password',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'CRUD Verification Before Update',
            'email' => 'crud-verification@example.test',
        ]);

        // SELECT
        $selectedUser = User::query()->findOrFail($user->id);

        $this->assertSame('CRUD Verification Before Update', $selectedUser->name);
        $this->assertSame('crud-verification@example.test', $selectedUser->email);

        // UPDATE
        $updatedRows = User::query()
            ->whereKey($user->id)
            ->update(['name' => 'CRUD Verification After Update']);

        $this->assertSame(1, $updatedRows);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'CRUD Verification After Update',
            'email' => 'crud-verification@example.test',
        ]);

        // DELETE
        $deletedRows = User::query()->whereKey($user->id)->delete();

        $this->assertSame(1, $deletedRows);
        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    }
}
