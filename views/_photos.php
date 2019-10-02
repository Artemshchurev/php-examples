<?php

/**
 * @var FeedbacksWidget  $this
 * @var PurchaseFeedback $purchaseFeedback
 * @var Orders           $order
 * @var string|null      $starsClass
 */
use Sp\Arr;
use YiiApp\components\SHtml;
use YiiApp\widgets\FeedbacksWidget\FeedbacksWidget;

$good = $order->goods;

/** @var PurchaseFeedbackGoodPicture[] $pictures */
$pictures = Arr::get($this->properties->userPictures, $purchaseFeedback->fpid . '.' . $good->gid);

$sizeName = $this->properties->getSize($order);
?>

<a href='/good/<?= $good->gid; ?>' class="img-wrapper" title="<?= $good->getName(); ?>">
    <?php $this->renderPhoto($good->picid, $good->getName()); ?>
</a>

<?php if ($pictures && $goodPicture = $pictures[0]): ?>
    <div class="user-photo-wrapper">
        <div class="user-photo" style="background-image: url(<?= SHtml::getImagePathById($goodPicture->picture_id, Pictures::THUMB_150); ?>);"></div>
        <div class="white"></div>
        <a class="zoom" href="<?= SHtml::getImagePathById($goodPicture->picture_id, Pictures::ORIGINAL); ?>"></a>
    </div>
    <div class="fotorama fotorama--hidden" data-auto="false" data-fit="contain">
        <?php
        $fotoramaPictures =
            $good->picid
                ? array_merge([$good->picid], Arr::pluck($pictures, 'picture_id'))
                : Arr::pluck($pictures, 'picture_id');
        ?>
        <?php foreach ($fotoramaPictures as $index => $pictureId): ?>
            <a href="<?= SHtml::getImagePathById((int)$pictureId, Pictures::ORIGINAL); ?>"
               data-caption="<?= $index === 0 ? 'Фото товара, загруженное организатором' : 'Фото товара, загруженное участником'; ?>"
               class="image-<?= $pictureId; ?>"
            >
                <?php $this->renderPhoto($pictureId, $good->getName()); ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($starsClass)): ?>
    <div class="stars-row <?= $starsClass; ?>"></div>
<?php endif; ?>

<div class="title cut">
    <?= $sizeName; ?>
    <a href="/good/<?= $good->gid; ?>">
        <?= $good->getName(); ?>
    </a>
</div>
