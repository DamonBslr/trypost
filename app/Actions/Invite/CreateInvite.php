<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Enums\UserWorkspace\Role as WorkspaceRole;
use App\Mail\WorkspaceInvite as WorkspaceInviteMail;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateInvite
{
    public static function execute(Workspace $workspace, array $data): Invite
    {
        $inviter = auth()->user();

        if (! $inviter instanceof User) {
            abort(403);
        }

        $email = data_get($data, 'email');
        $roleValue = data_get($data, 'role');

        if (is_array($roleValue)) {
            $roleValue = data_get($roleValue, '0');
        }

        $role = is_string($roleValue) ? WorkspaceRole::tryFrom($roleValue) : null;

        if ($role === null) {
            throw ValidationException::withMessages([
                'role' => __('validation.required', ['attribute' => 'role']),
            ]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('settings.members.errors.email_belongs_to_account'),
            ]);
        }

        $existing = Invite::query()
            ->where('account_id', $workspace->account_id)
            ->where('email', $email)
            ->first();

        if ($existing && $existing->accepted_at === null) {
            throw ValidationException::withMessages([
                'email' => __('settings.members.errors.invite_exists'),
            ]);
        }

        try {
            if ($existing) {
                $existing->update([
                    'invited_by' => $inviter->id,
                    'role' => $role,
                    'workspaces' => [$workspace->id],
                    'accepted_at' => null,
                ]);

                $invite = $existing->refresh();
            } else {
                $invite = Invite::create([
                    'account_id' => $workspace->account_id,
                    'invited_by' => $inviter->id,
                    'email' => $email,
                    'role' => $role,
                    'workspaces' => [$workspace->id],
                ]);
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => __('settings.members.errors.invite_exists'),
            ]);
        }

        $invite->loadMissing('account');

        try {
            Mail::to($invite->email)
                ->locale($inviter->preferredLocale())
                ->send(new WorkspaceInviteMail($invite));
        } catch (Throwable $e) {
            report($e);
        }

        return $invite;
    }
}
