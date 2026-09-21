<?php

declare(strict_types=1);

use App\Actions\Invite\CreateInvite;
use App\Enums\UserWorkspace\Role as WorkspaceRole;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

beforeEach(function () {
    config(['trypost.self_hosted' => true]);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => WorkspaceRole::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('create invite still persists when the mailer throws', function () {
    $this->actingAs($this->user);

    Exceptions::fake();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp unavailable'));

    $invite = CreateInvite::execute($this->workspace, [
        'email' => 'queued-fail@example.com',
        'role' => WorkspaceRole::Member->value,
    ]);

    expect($invite)->toBeInstanceOf(Invite::class);
    $this->assertDatabaseHas('invites', [
        'email' => 'queued-fail@example.com',
        'account_id' => $this->account->id,
    ]);
    Exceptions::assertReported(RuntimeException::class);
});
