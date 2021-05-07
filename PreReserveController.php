<?php

namespace App\Http\Controllers\V4\Clients;

use App\Database\Criteria;
use App\Database\Models\Notification;
use App\Events\Log\ReserveCreateEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\V4\Clients\PreReserves\PreReserveCancelRequest;
use App\Http\Requests\V4\Clients\PreReserves\PreReserveTransformRequest;
use App\Http\Requests\V4\Clients\PreReserves\PreReserveUpdateRequest;
use Carbon\Carbon;
use App\Traits\CompanyShowTrait;
use Illuminate\Http;

class PreReservesController extends Controller {

    use CompanyShowTrait;

    /** @var \App\Contracts\IAngaraRequests */
    protected $angaraRequests;

    /** @var \App\Libraries\BPMNWorker */
    protected $bpmnWorker;

    /** @var \App\Decorators\Datasources\DatabaseRepo\Company\Client */
    protected $companyClientRepo;

    /** @var \App\Decorators\Datasources\DatabaseRepo\Company */
    protected $companyRepo;

    /** @var \App\Contracts\IFields */
    protected $fieldsLibrary;

    /** @var \App\Contracts\ICompanies */
    protected $companiesLibrary;

    /** @var \App\Contracts\IServices */
    protected $servicesLibrary;

    /** @var \App\Contracts\IResources */
    protected $resourcesLibrary;

    /** @var \App\Contracts\IImages */
    protected $imagesLibrary;

    /** @var \App\Decorators\Datasources\DatabaseRepo\Car */
    protected $carRepo;

    /** @var \App\Decorators\Datasources\DatabaseRepo\Car\Prereserve */
    protected $carPreReservesRepo;

    /** @var \App\Decorators\Datasources\DatabaseRepo\Client */
    protected $clientRepo;

    /** @var \App\Decorators\Datasources\DatabaseRepo\Car\Reserve */
    protected $carReserveRepo;

    /** @var \App\Decorators\Datasources\DatabaseRepo\Client\Notifications\Prepared */
    protected $clientNotificationsPreparedRepo;

    public function show(int $id) {
        $preReserve = $this->angaraRequests->getPreReserve($id, ['expand' => 'service,serviceTemplate']);
        if (isset($preReserve['service'])) {
            $companyId = $preReserve['service']['company']['id'];
            $company = $this->pickCompany($companyId, 'files,files.thumbs');
            $preReserve['company'] = $company;
        }
        $preReserve['now'] = Carbon::now()->toAtomString();
        $clientId = \Auth::user()['client_id'];
        $carPreReserve = $this->carPreReservesRepo
            ->get((new Criteria('prereserve_id', $id))
                ->pushParam('client_id', $clientId));
        if ($carPreReserve->isNotEmpty() && $carId = $carPreReserve['car_id']) {
            $car = $this->carRepo->getWithFiles($carId, $clientId);
            if (isset($car['message'])) {
                return $this->jsonResponse([
                    'message' => $car['message'],
                ], Http\Response::HTTP_BAD_REQUEST);
            }
            $preReserve['car'] = $car;
        }

        return $this->jsonResponse($preReserve);
    }

    public function update(PreReserveUpdateRequest $request, int $id) {
        $preReserve = $this->angaraRequests->updatePreReserve($id, [
            'scheduledTo' => $request->get('scheduled_to'),
            'completesAt' => $request->get('completes_at'),
            'origin' => $request->get('origin', 'assistant'),
        ]);

        return $this->jsonResponse($preReserve);
    }

    public function cancel(PreReserveCancelRequest $request, int $id) {
        $preReserve = $this->angaraRequests->updatePreReserve($id, [
            'status' => 'CANCELED',
            'origin' => $request->get('origin', 'assistant'),
        ]);
        $this->deletePrereserveNotifications($id);
        $this->bpmnWorker->sendPreReserveDeclined($id);

        return $this->jsonResponse($preReserve);
    }

    public function transformToReserve(int $id, PreReserveTransformRequest $request) {
        $clientId = \Auth::user()['client_id'];
        $carId = $request->get('car_id');
        $origin = $request->get('origin', 'assistant');
        $resourceIds = $request->get('resources');

        $preReserve = $this->angaraRequests->getPreReserve($id);
        $companyId = $preReserve['company']['id'];
        $servicesIds = [
            $preReserve['service']['id'],
        ];

        $client = $this->clientRepo->get(new Criteria('id', $clientId));
        $this->clientRepo->sync(
            $client['id'],
            'favorites',
            [$companyId],
            false
        );

        $reserve = null;
        $durations = $this->resourcesLibrary->getDurationsAndPricesByCompanyAndServices(
            $resourceIds,
            $servicesIds,
            $companyId,
            $this->angaraRequests
        );
        foreach ($resourceIds as $resourceId) {
            $reserve = $this->angaraRequests->transformPreReserveToReserve($id, [
                'completesAt' => $request->get('completes_at'),
                'resource' => [
                    'id' => $resourceId,
                ],
                'duration' => $durations['durations']
                    ? $durations['durations'][$resourceId]
                    : null,
                'descr' => $request->get('descr'),
                'origin' => $origin,
            ]);

            if (isset($reserve['message'])
                && isset($reserve['advanced'])
                && in_array($reserve['advanced']['label'], config('project.angaraCollisionErrors'))) {
                continue;
            }
            break;
        }

        if (!$reserve || isset($reserve['message'])) {
            return ['message' => trans('messages.reserve_create_error')];
        }

        if (!$carId) {
            $carPreReserve = $this->carPreReservesRepo->get(new Criteria('prereserve_id', $id));
            if ($carPreReserve->isNotEmpty()) {
                $carId = $carPreReserve['car_id'];
            }
        }

        $this->deletePrereserveNotifications($id);
        $this->bpmnWorker->sendInviteConfirmed($id);
        if ($carId) {
            $car = $this->carRepo->getByClientId($clientId, new Criteria('id', $carId));

            if ($car->count()) {
                $this->carReserveRepo->store(
                    [
                        'car_id' => $carId,
                        'reserve_id' => $reserve['id'],
                        'client_id' => $client['id'],
                    ]
                );
                $reserve['car'] = $car->toArray();
            }
        }

        \Event::fire(new ReserveCreateEvent(
            $origin,
            $companyId,
            $client['id'],
            $reserve
        ));

        return ['reserve' => $reserve];
    }

    public function confirm(int $id) {
        $this->deletePrereserveNotifications($id);
        $this->bpmnWorker->sendPreReserveConfirmed($id);

        return $this->jsonResponse(['id' => $id]);
    }

    private function deletePrereserveNotifications(int $id):void {
        $notifications = \DB::table('notifications_pre_reserves')
            ->where('pre_reserve_id', $id)
            ->select('notification_id')
            ->get()
            ->toArray();
        $notificationsIds = array_map(function($notification) {
            return $notification->notification_id;
        }, $notifications);
        Notification::whereIn('id', $notificationsIds)
            ->delete();
    }
}
