<?php
/**
 * @var FeedbacksWidget  $this
 * @var PurchaseFeedback $purchaseFeedback
 * @var int              $mid
 */
use Legacy\Picture\Picture_PictureUrl;
use Sp\Arr;
use YiiApp\components\SHtml;
use YiiApp\helpers\Utils;
use YiiApp\widgets\FeedbacksWidget\FeedbacksWidget;

if (!$this->properties->isPurchaseManageAccess && !$this->properties->isModeratorAccess) {
    return;
}

?>

<?php if ($this->properties->isModeratorAccess): ?>
    <div class="feed-form">
        <?= SHtml::textArea("{$purchaseFeedback->fpid}_comment", SHtml::encode($purchaseFeedback->comment), [
            'data-id' => $purchaseFeedback->fpid,
            'class' => 'comment',
        ]); ?>
        <?php if ($purchaseFeedback->org_answer): ?>
            <?= SHtml::textArea("{$purchaseFeedback->fpid}_org_answer", SHtml::encode($purchaseFeedback->org_answer),
                [
                    'rows' => '4',
                    'class' => 'org_answer span12',
                ]); ?>
        <?php endif; ?>
        <div class="edit-controls">
            <button class="btn comment-save">Сохранить</button>
            <a class="btn cancel" href="#">Отмена</a>
        </div>
        <?php $userPicturesForGoods = Arr::get($this->properties->userPictures, $purchaseFeedback->fpid); ?>
        <?php if ($userPicturesForGoods): ?>
            <div class="present-images">
                <?php foreach ($userPicturesForGoods as $key => $userPicturesForGood): ?>

                    <?php $goodName = $key; ?>
                    <?php foreach ($this->properties->getOrders($purchaseFeedback->mid) as $order): ?>
                        <?php if ($order->goods): ?>
                            <?php if ((int)$order->goods->gid === (int)$key): ?>
                                <?php $goodName = $order->goods->getName(); ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <div class="feedback-good">
                        <h4 class="title">
                            <?= $goodName; ?>
                        </h4>
                        <div class="images">
                            <?php /** @var PurchaseFeedbackGoodPicture $picture */ ?>
                            <?php foreach ($userPicturesForGood as $picture): ?>
                                <div class="image image-<?= $picture->picture_id; ?>">
                                    <?= CHtml::link('', null, [
                                        'class' => 'icon-remove remove-image',
                                        'data-feedback-id' => $picture->feedback_id,
                                        'data-good-id' => $picture->good_id,
                                        'data-picture-id' => $picture->picture_id,
                                    ]); ?>
                                    <span class="img-wrapper">
                                                <?= Picture_PictureUrl::getImageTag(
                                                    $picture->picture_id,
                                                    Pictures::THUMB_100, '',
                                                    [
                                                        'lazyload' => (($key > 5) ? true : false),
                                                        'alt' => '',
                                                    ]
                                                ); ?>
                                            </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="edit_controls">
    <?php if ($this->properties->isPurchaseManageAccess): ?>
        <?= SHtml::link('Ответить', '#', [
            'class' => 'btn answer-link' . ($purchaseFeedback->org_answer ? ' hide' : ''),
            'data-id' => $purchaseFeedback->fpid,
        ]); ?>
    <?php endif; ?>

    <?php if ($this->properties->isModeratorAccess): ?>
        <?= SHtml::link('Редактировать', ['#'], [
            'class' => 'btn edit-feed',
        ]); ?>
        <?php if (!$purchaseFeedback->isRejected()): ?>
            <?= SHtml::link('Отклонить', ['/mp/moderation/feedbacks/reject', 'id' => $purchaseFeedback->fpid], [
                'class' => 'btn reject',
            ]); ?>
        <?php else: ?>
            <span style="color: #c90000">Отклонен</span>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($this->properties->isModeratorAccess || $this->properties->isPurchaseManageAccess): ?>
        <?php $showAnswerDeleteButton = $purchaseFeedback->org_answer
            && $purchaseFeedback->org_answered_at
            && ($this->properties->isModeratorAccess || ($this->properties->isPurchaseManageAccess && Utils::compareDates($purchaseFeedback->org_answered_at, new DateTime(PurchaseFeedback::TTL_ANSWER_REMOVE_BUTTON)) > 0)); ?>
        <?= SHtml::link('Удалить ответ',
            ['/feedbacks/default/removeAnswer', 'id' => $purchaseFeedback->fpid], [
                'class' => 'btn remove-answer' . (!$showAnswerDeleteButton ? ' hide' : ''),
            ]); ?>
    <?php endif; ?>
</div>
