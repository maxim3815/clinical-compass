<?php

use App\Broadcasting\ChatChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Счётчики непрочитанного: только сам пользователь.
Broadcast::channel('App.Models.User.{id}', fn (User $user, string $id): bool => $user->id === (int) $id);

Broadcast::channel('chat.{careRelationshipId}', ChatChannel::class);
