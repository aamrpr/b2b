<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * خرق قاعدة عمل — خطأ في المُدخَل لا عطل في الخادم.
 *
 * يرث RuntimeException ليبقى كل ما يلتقطه عاملاً، لكنه يُعرَض 422
 * فيميّز العميل بين "طلبك غير صحيح" و"الخادم تعطّل".
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function context(): array
    {
        return $this->context;
    }
}
