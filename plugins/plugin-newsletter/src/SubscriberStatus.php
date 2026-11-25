<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

/**
 * 購読の状態。pending（確かめのメール待ち）→ active（届ける）→ unsubscribed（止めた）。止めた人がまた選ぶと pending か active に戻る
 */
enum SubscriberStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Unsubscribed = 'unsubscribed';
}
