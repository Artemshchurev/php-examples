<?php

/**
 * @var FeedbacksWidget $this
 */
use Sp\Sp;
use YiiApp\components\SHtml;
use YiiApp\widgets\BodyOptionsWidget\BodyOptionsWidget;
use YiiApp\widgets\FeedbacksWidget\FeedbacksWidget;
use YiiApp\widgets\LocalDate;

?>

<?php foreach ($this->properties->feedbacks as $key => $purchaseFeedback): ?>
    <?php
    $mid = (int)$purchaseFeedback->mid;

    $this->properties->setLazyLoad($key);
    $created = $purchaseFeedback->created;

    $goodsFeedbacks = [];
    $renderedGoods = [];
    foreach ($purchaseFeedback->goodsFeedbacks as $feedback) {
        // Если товара, к которому оставлен отзыв, нет в мегазаказе, то не показываем его
        if (!$this->properties->isGoodFeedbackInOrder($mid, (int)$feedback->good_id)) {
            continue;
        }
        // Если мы находимся на странице товара, то в отзыве на покупку не показываем текущий товар в разделе "С этим товаром покупали"
        if ($this->type === $this::TYPE_GOOD
            && $this->good
            && $feedback->good_id
            && $this->duplicatedGoodIds
            && !in_array((int)$feedback->good_id, $this->duplicatedGoodIds, true)) {
            continue;
        }

        $goodsFeedbacks[] = $feedback;
        $renderedGoods[(int)$feedback->good_id] = 1;
    }

    $orders = [];
    foreach ($this->properties->getOrders($mid) as $order) {
        if (!$this->hideGoodsInPurchaseFeedback && $order->goods && empty($renderedGoods[(int)$order->goods->gid])) {
            $orders[] = $order;
        }
    }

    $params = [
        'purchaseFeedback' => $purchaseFeedback,
        'goodsFeedbacks' => $goodsFeedbacks,
        'renderedGoods' => $renderedGoods,
        'orders' => $orders,
    ];
    ?>

    <div class="feedback-item<?= $purchaseFeedback->isRejected() ? ' rejected' : ''; ?>" id="feed<?= $purchaseFeedback->fpid; ?>">
        <div class="left-wrapper">
            <div class="avatar-wrapper" style="background-image: url(<?= SHtml::getAvatarOrStubPath($purchaseFeedback->user, Pictures::THUMB_150); ?>)"></div>
            <div class="feedback-user">
                <?php if (Yii::app()->isAdminOrModerator()): ?>
                    <?= SHtml::userNameWithRatio($purchaseFeedback->user, true); ?>
                <?php else: ?>
                    <?= $purchaseFeedback->user->fname; ?>
                    <?= SHtml::ratioFormat($purchaseFeedback->user, false); ?>
                <?php endif; ?>

                <?php if ($this->properties->isPurchaseManageAccess): ?>
                    <?= SHtml::createMessageLink([
                        'connected_type' => MessageDialogs::T_PURCHASE,
                        'connected_id' => $purchaseFeedback->pid,
                        'uid' => $purchaseFeedback->user->uid,
                    ], '', ['rel' => 'nofollow']); ?>
                <?php endif; ?>
            </div>
            <div class="muted feedback-date">
                <?= $created !== '0000-00-00 00:00:00' ? LocalDate::dayMonthYear($created) : '&nbsp;'; ?>
            </div>

            <?php
            if ($this->canShowBodyOptions($mid)) {
                $this->widget(BodyOptionsWidget::class, [
                    'model' => $purchaseFeedback->user->bodyOptions,
                    'displayEmpty' => false,
                    'title' => '',
                    'enable' => true,
                ]);
            }
            ?>

        </div>
        <div class="right-wrapper">
            <?php $comment = SHtml::encode(trim($purchaseFeedback->comment), true); ?>

            <?php if ($comment): ?>
                <p class="comment_<?= $purchaseFeedback->fpid; ?>"><?= $comment; ?></p>
            <?php endif; ?>

            <?php if ($purchaseFeedback->org_answer): ?>
                <div class="org-answer org_answer_<?= $purchaseFeedback->fpid; ?>">
                    <?php if ($this->properties->orgUser): ?>
                        <?= SHtml::orgNameWithRatio($this->properties->orgUser); ?>
                    <?php endif; ?>
                    <?= SHtml::encode($purchaseFeedback->org_answer); ?>
                </div>
            <?php endif; ?>

            <?php $this->render('_edit_controls', get_defined_vars()); ?>

            <?php if (!($this->type == $this::TYPE_PURCHASE_PAGE && Sp::isMobileVersion())): ?>
                <?php $this->render('_good_item', $params); ?>
            <?php endif; ?>

            <?php if ($this->properties->isCommonList && !Yii::app()->user->isGuest): ?>
                <div class="feedback-purchase">
                    <?= SHtml::purchaseLink($purchaseFeedback->purchase); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="f-like-wrapper">
            <?= SHtml::likeHeart($purchaseFeedback->likes, $purchaseFeedback->fpid, 'feedback', [
                'color' => $this->properties->userLikes->inSet("feedback{$purchaseFeedback->fpid}", false) ? 'red' : 'grey',
            ]); ?>
        </div>
    </div>
<?php endforeach; ?>
