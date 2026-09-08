<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * Data model for a user returned by the Databridge API.
 *
 * Carries no password, session, 2FA or reset-token fields: this exists so a client can put a
 * name to a username, not to mirror zp_user.
 */
readonly class UserData
{
    /**
     * @param  int  $id  User ID.
     * @param  string  $username  Login name (an email address), and the value the
     *                            ticket and timesheet endpoints expect as "username".
     * @param  string  $firstname  Given name.
     * @param  string  $lastname  Family name.
     * @param  ?string  $jobTitle  Free-text job title, or null when unset.
     * @param  ?string  $department  Free-text department, or null when unset.
     * @param  list<int>  $projects  Granted project IDs this user is assigned to. Already
     *                               filtered to the calling key's own grant, so it never
     *                               reveals projects the key cannot itself reach.
     */
    public function __construct(
        public int $id,
        public string $username,
        public string $firstname,
        public string $lastname,
        public ?string $jobTitle,
        public ?string $department,
        public array $projects,
    ) {}
}
