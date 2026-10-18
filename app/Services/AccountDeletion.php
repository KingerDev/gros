<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Zmazanie účtu so všetkými dátami (App Store to vyžaduje priamo v appke).
 *
 * Cudzie kľúče na users majú cascadeOnDelete, ale mažeme aj explicitne —
 * nezávisí to od toho, či má databáza zapnuté FK (SQLite), a pokryje aj
 * tabuľky bez FK (sessions, tokeny, reset hesla).
 */
class AccountDeletion
{
    public function __construct(protected AppleIdentity $apple) {}

    public function delete(User $user): void
    {
        // Apple: zrušiť prepojenie appky s Apple ID (chyby sa len zalogujú)
        if ($user->apple_refresh_token) {
            $this->apple->revoke($user->apple_refresh_token);
        }

        DB::transaction(function () use ($user) {
            $id = $user->id;

            // poradie: najprv to, čo odkazuje na účty, kategórie a udalosti
            DB::table('transactions')->where('user_id', $id)->update(['refund_for_id' => null]);
            DB::table('transactions')->where('user_id', $id)->delete();
            DB::table('budgets')->where('user_id', $id)->delete();
            DB::table('subscriptions')->where('user_id', $id)->delete();
            DB::table('loans')->where('user_id', $id)->delete();
            DB::table('goals')->where('user_id', $id)->delete();

            $investmentIds = DB::table('investments')->where('user_id', $id)->pluck('id');
            DB::table('investment_transactions')->whereIn('investment_id', $investmentIds)->delete();
            DB::table('investments')->where('user_id', $id)->delete();

            $chatIds = DB::table('chats')->where('user_id', $id)->pluck('id');
            DB::table('chat_messages')->whereIn('chat_id', $chatIds)->delete();
            DB::table('chats')->where('user_id', $id)->delete();

            DB::table('events')->where('user_id', $id)->delete();
            // podkategórie pred skupinami (parent_id)
            DB::table('categories')->where('user_id', $id)->whereNotNull('parent_id')->delete();
            DB::table('categories')->where('user_id', $id)->delete();
            DB::table('accounts')->where('user_id', $id)->delete();

            DB::table('push_tokens')->where('user_id', $id)->delete();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            $user->delete();
        });
    }
}
