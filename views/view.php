<?php

/**
 * @var FeedbacksWidget $this
 */
use YiiApp\widgets\FeedbacksWidget\FeedbacksWidget;

?>

<div class="feedback">
    <?php if ($this->type === $this::TYPE_GOOD): ?>
        <h3>Отзывы</h3>
    <?php endif; ?>

    <div class="fox-tile">
        <div class="feedback-feed">
            <?php $this->render('_list'); ?>
        </div>
    </div>
</div>

<?php if ($this->properties->pagination && $this->showPager): ?>
    <div class="pagination de-pagination">
        <?php $this->widget(TbPager::class, [
            'pages' => $this->properties->pagination,
            'prevPageLabel' => 'Назад',
            'nextPageLabel' => 'Дальше',
        ]); ?>
    </div>
<?php endif; ?>

<?php if ($this->type === $this::TYPE_GOOD && $this->properties->megapurchase): ?>
    <a class="allFeedbacks" href="/feedbacks/megapurchase/<?= $this->properties->megapurchase->id; ?>">Показать все отзывы</a>
<?php endif; ?>
