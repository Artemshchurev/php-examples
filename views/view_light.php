<?php

/**
 * @var FeedbacksWidget $this
 */
use Legacy\Html;
use Legacy\System\System_String;
use YiiApp\widgets\FeedbacksWidget\FeedbacksWidget;

if (!$this->properties->feedbacks) {
    return;
}

$href = "/feedbacks/megapurchase/{$this->megapurchase->id}";
$viewAll = "<a href='{$href}'>Все отзывы</a>";
?>

<?= $viewAll; ?>

<?php foreach ($this->properties->feedbacks as $feedback): ?>
    <div class="item clearfix">
        <div class="user"><?= $feedback->user->fname; ?></div>
        <div class="text"><?= Html::link(
            System_String::limiterWithDots($feedback['comment'], 140),
            $href . '?feed=' . $feedback->fpid,
            ['rel' => 'nofollow']
        ); ?></div>
    </div>
<?php endforeach; ?>

<?= $viewAll; ?>
