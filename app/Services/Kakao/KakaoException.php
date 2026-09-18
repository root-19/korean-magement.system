<?php

namespace App\Services\Kakao;

use RuntimeException;

/**
 * A refusal from Kakao, carrying wording an instructor can act on.
 *
 * Kakao answers with `{"msg": ..., "code": ...}` and the codes that actually
 * come up are configuration, not bugs: -402 is the app missing the
 * `talk_message` permission, KOE006 a redirect URI that does not match the
 * console. Those have to reach the person who can fix them instead of being
 * swallowed into a generic failure.
 */
class KakaoException extends RuntimeException
{
    public static function from(string $step, ?string $message, int|string|null $code): self
    {
        $detail = trim((string) $message);

        return new self(sprintf(
            'Kakao refused the %s step%s.',
            $step,
            $detail === '' ? '' : sprintf(' (%s%s)', $detail, $code === null ? '' : ', code '.$code),
        ));
    }
}
