<?php

/**
 * @var FeedbacksWidget      $this
 * @var PurchaseFeedback     $purchaseFeedback
 * @var PurchaseFeedbackGood $goodsFeedbacks
 * @var Orders[]             $orders
 */
use YiiApp\components\SHtml;
use YiiApp\widgets\FeedbacksWidget\FeedbacksWidget;

if (!$goodsFeedbacks && !$orders) {
    return;
}

$mid = (int)$purchaseFeedback->mid;
$itemClass = $this->properties->isCommonList ? 'good-item' : '';
?>

<div class="goods purchase-feedback-goods">
    <?php foreach (array_slice($orders, 0, 8) as $order): ?>
        <div class="<?= $itemClass; ?>">
            <?php $this->render('_photos', [
                'purchaseFeedback' => $purchaseFeedback,
                'order' => $order,
            ]); ?>
        </div>
    <?php endforeach; ?>

    <?php foreach ($goodsFeedbacks as $feedback): ?>
        <?php
        $comment = SHtml::encode(trim($feedback->comment), true);

        $starsCount = (int)$feedback->mark;
        $starsClass = $starsCount >= 1 && $starsCount <= 5 ? "stars{$starsCount}0" : null;

        $withComment = $comment || $feedback->size_match;
        $commentGreyBlock = $withComment && $this->type != FeedbacksWidget::TYPE_GOOD;

        $goodOrders = $this->properties->getOrders($mid, $feedback->good_id);
        ?>

        <div class="<?= $itemClass; ?><?= $commentGreyBlock ? ' with_comment' : ''; ?>">
            <?php if ($this->properties->isCommonList): ?>
                <div class="goods">
                    <?php foreach ($goodOrders as $order): ?>
                        <?php
                        $this->render('_photos', [
                            'purchaseFeedback' => $purchaseFeedback,
                            'order' => $order,
                            'starsClass' => $starsClass,
                        ]); ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php if (!empty($starsClass)): ?>
                    <div class="stars-row <?= $starsClass; ?>"></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($withComment): ?>
                <div class="good_comment comment_<?= $purchaseFeedback->fpid; ?>">
                    <?php if ($comment): ?>
                        <p><?= $comment; ?></p>
                    <?php endif; ?>
                    <?php if ($feedback->size_match): ?>
                        <p class="muted">
                            <?php if ($feedback->size_match): ?>
                                <?php $sizeName = !empty($goodOrders[0]) ? $this->properties->getSize($goodOrders[0]) : null; ?>
                                Соответствие размеру:<br/><?= $sizeName ? ' ' . $sizeName . ' - ' : ''; ?><?= mb_strtolower(PurchaseFeedbackGood::SIZE_MATCH[$feedback->size_match]); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
