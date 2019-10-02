<?php

namespace YiiApp\widgets\FeedbacksWidget;

use CActiveDataProvider;
use CPagination;
use CWidget;
use Exception;
use Goods;
use Legacy\Picture\Picture_PictureUrl;
use Likes;
use Megapurchases;
use Orders;
use Pictures;
use PurchaseFeedback;
use PurchaseFeedbackGoodPicture;
use Users;
use Yii;
use YiiApp\helpers\Cache;
use YiiApp\helpers\CacheSet;

class FeedbacksWidget extends CWidget
{
    public const TYPE_MEGAPURCHASE = 'MEGAPURCHASE';
    public const TYPE_GOOD = 'GOOD';
    public const TYPE_ORG = 'ORG';
    public const TYPE_USER = 'USER';
    public const TYPE_PURCHASE_PAGE = 'PURCHASE_PAGE';

    /** @var string $type */
    public $type;

    /** @var Goods|null $good */
    public $good;

    /** @var bool $canBeEditable */
    public $canBeEditable;

    /** @var FeedbackWidgetProperties $properties */
    protected $properties;

    /** @var int[] $duplicatedGoodIds */
    public $duplicatedGoodIds = [];

    /** @var Megapurchases|null $megapurchase */
    public $megapurchase;

    /** @var PurchaseFeedback|null $extraFeedback */
    public $extraFeedback;

    /** @var Users|null $org */
    public $org;

    /** @var Users|null $user */
    public $user;

    /** @var string $search */
    public $search;

    /** @var int $limit */
    public $limit;

    public $showPager = true;

    public $hideGoodsInPurchaseFeedback = false;

    public function getTotalItemCount(): int
    {
        return $this->properties->pagination->getItemCount();
    }

    public function init(): void
    {
        if (!$this->limit) {
            $this->limit = $this->type == self::TYPE_GOOD ? 45 : 90;
        }

        if ($this->extraFeedback && $this->type !== self::TYPE_MEGAPURCHASE) {
            throw new Exception('Extra feedback can be shown only at page with purchase type');
        }
        $this->properties = new FeedbackWidgetProperties();
        $this->properties->isCommonList = \in_array($this->type, [self::TYPE_ORG, self::TYPE_MEGAPURCHASE, self::TYPE_USER, self::TYPE_PURCHASE_PAGE]);

        if ($this->type === self::TYPE_PURCHASE_PAGE) {
            $this->properties->feedbacks = $this->findRandomByMegapurchase($this->megapurchase, $this->limit);
        } elseif ($this->extraFeedback) {
            $this->properties->feedbacks = [$this->extraFeedback];
        } else {
            try {
                PurchaseFeedback::$db = Yii::app()->getDbReplica();

                switch ($this->type) {
                    case self::TYPE_GOOD:
                        $this->hideGoodsInPurchaseFeedback = true;

                        $this->properties->dataProvider = PurchaseFeedback::model()->findByGoodIds($this->duplicatedGoodIds, $this->limit);
                        break;
                    case self::TYPE_USER:
                        $this->properties->dataProvider = PurchaseFeedback::model()->findByUserId($this->user, $this->limit);
                        break;
                    case self::TYPE_MEGAPURCHASE:
                        if (!$this->megapurchase) {
                            return;
                        }

                        $this->properties->dataProvider = PurchaseFeedback::model()->findByMegapurchaseId($this->megapurchase, $this->limit, $this->search);
                        break;
                    case self::TYPE_ORG:
                        $this->properties->dataProvider = PurchaseFeedback::model()->findByOrgId($this->org, $this->limit);
                        break;
                }

                $this->properties->feedbacks = $this->properties->dataProvider->getData();
                if ($this->showPager && $this->properties->isCommonList) {
                    $this->properties->pagination = $this->properties->dataProvider->getPagination();
                    $this->properties->pagination->setItemCount($this->properties->dataProvider->getTotalItemCount());
                }
            } finally {
                PurchaseFeedback::$db = Yii::app()->db;
            }
        }

        $this->properties->userPictures = PurchaseFeedbackGoodPicture::findForFeedbacks($this->properties->feedbacks);
        $this->properties->setOrders();
        $this->properties->userLikes = Likes::model()->getCachedSet(Yii::app()->user->id);

        if ($this->type === self::TYPE_GOOD && $this->properties->feedbacks) {
            $this->properties->megapurchase = $this->properties->feedbacks[0]->purchase->megapurchase;
        }

        if ($this->canBeEditable) {
            $this->properties->isModeratorAccess = Yii::app()->user->getIsModerator();
            if ($this->properties->feedbacks) {
                $purchase = $this->properties->feedbacks[0]->purchase;
                $this->properties->orgUser = $purchase->user;
                if ($this->type === self::TYPE_MEGAPURCHASE) {
                    $this->properties->isPurchaseManageAccess = Yii::app()->user->checkAccess('purchaseManage', compact('purchase'));
                }
            }
        }

        // Если мы находимся на странице товара, то в отзыве на покупку не показываем текущий товар в разделе "С этим товаром покупали"
        if ($this->type === self::TYPE_GOOD && $this->good && $this->properties->orders) {
            $filteredOrders = [];
            foreach ($this->properties->orders as $key => $feedbackOrders) {
                $filteredOrders[$key] = array_filter($feedbackOrders, function (Orders $order) {
                    return $order->goods && !\in_array((int)$order->goods->gid, $this->duplicatedGoodIds);
                });
            }
            $this->properties->orders = $filteredOrders;
        }
    }

    public function run(): void
    {
        if (\count($this->properties->feedbacks) === 0) {
            return;
        }

        Yii::app()->clientScript->requireWebpackModule('feedbacks');

        $this->render((!Yii::app()->mobileDetect->isMobileVersion() && $this->type === self::TYPE_PURCHASE_PAGE) ? 'view_light' : 'view');
    }

    public function getDataProvider(): CActiveDataProvider
    {
        return $this->properties->dataProvider;
    }

    protected function canShowBodyOptions(int $mid): bool
    {
        if ($this->type !== $this::TYPE_GOOD) {
            foreach ($this->properties->getOrders($mid) as $order) {
                if ($order->goods && $order->goods->collection && $order->goods->collection->isCategoryContainsSizeTable()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findRandomByMegapurchase(Megapurchases $megapurchase, int $count)
    {
        $cache = new CacheSet(__METHOD__ . ".mid.{$megapurchase->id}.count.${count}");

        if (!$cache->isExists()) {
            $yearAgo = date('Y-m-d', strtotime('-2 year'));
            $dataProvider = PurchaseFeedback::model()->findByMegapurchaseId(
                $megapurchase,
                50,
                null,
                'year(pf.created) desc, pf.likes DESC, pf.created DESC',
                "pf.created > '${yearAgo}' and " . PurchaseFeedback::SQL_WITH_COMMENT
            );
            $feedbacks = $dataProvider->getData();

            if ($feedbacks) {
                $cache->add($feedbacks);
                $cache->expire(Cache::T_MINUTE * 30);
            }
        }
        $feedbacks = $cache->getRandom($count);
        $this->properties->pagination = new CPagination();
        $this->properties->pagination->setItemCount(\count($feedbacks));

        return $feedbacks;
    }

    protected function renderPhoto(?int $picId, ?string $name): void
    {
        $picId = (int)$picId;

        echo Picture_PictureUrl::getImageTag(
            $picId,
            Pictures::THUMB_150,
            '',
            [
                'lazyload' => $this->properties->lazyLoad,
                'alt' => $name,
            ]
        );
    }
}
