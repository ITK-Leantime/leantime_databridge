<?php

namespace Leantime\Plugins\Databridge\Model;

use Carbon\CarbonInterface;

/**
 * Metadata for a file attached to a ticket.
 *
 * Metadata only, by design: the Databridge API never exposes file contents. zp_file stores
 * no size column and the bytes live in the configured storage backend (local or S3), so
 * neither a size nor a download URL is offered here.
 */
readonly class FileData
{
    /**
     * @param  int  $id  File ID.
     * @param  int  $ticketId  The ticket the file is attached to (zp_file.moduleId).
     * @param  ?string  $filename  Original upload name (zp_file.realName).
     * @param  ?string  $extension  File extension as recorded on upload.
     * @param  ?int  $userId  Uploader's zp_user id.
     * @param  ?string  $username  Uploader's username (email), null when the user is gone.
     * @param  ?CarbonInterface  $uploaded  Upload datetime.
     */
    public function __construct(
        public int $id,
        public int $ticketId,
        public ?string $filename,
        public ?string $extension,
        public ?int $userId,
        public ?string $username,
        public ?CarbonInterface $uploaded,
    ) {}
}
