<?php


namespace App\Libraries\Car;

use App;
use App\Contracts\ICarInviteDate;
use App\Database\Criteria;
use App\Database\Models\Car;
use App\Database\Models\Car\Mileage;
use App\Database\Models\Car\SeasonalPreference;
use App\Database\Models\Packet;
use App\Helpers\ExceptionCatcher;
use App\Libraries\Main;
use Carbon\Carbon;

class CarInviteDate extends Main implements ICarInviteDate {

    /** @var \App\Decorators\Datasources\DatabaseRepo\CarOil */
    protected $carOilRepo;

    /** @var \App\Decorators\Datasources\DatabaseRepo\CarChangeOilMonth */
    protected $carOilMonthsRepo;

    /** @var \App\Decorators\Datasources\PlainSQLRepo\Cars */
    protected $carSQL;

    /** @var \App\Contracts\IMileageStatistic */
    protected $mileageStatisticLibrary;

    /** @var \App\Contracts\IAngaraRequests */
    protected $angaraRequests;

    /** @var \App\Decorators\Datasources\DatabaseRepo\Client */
    protected $clientRepo;

    /** @var \App\Contracts\IStatistics */
    protected $statistics;

    public function getInviteDate(int $packetId, int $carId, int $clientId, int $companyId, string $companyName): array {
        $packet = Packet::find($packetId);
        if ($packet === null) {
            ExceptionCatcher::process(
                new \Exception(trans('messages.absent_packet')),
                trans('messages.absent_packet')
            );
            return [
                'message' => trans('messages.absent_packet'),
            ];
        } else if ($packet->calculation_type === Packet::CALC_TYPE_CYCLE) {
            $inviteDate = $this->getDateForSeason($carId);
        } elseif ($packet->calculation_type === Packet::CALC_TYPE_MILEAGE) {
            $inviteDate = $this->getDateForMileage($carId);
        } elseif ($packet->calculation_type === Packet::CALC_TYPE_CASCO) {
            $inviteDate = $this->getDataForCasco($carId);
        } elseif ($packet->calculation_type === Packet::CALC_TYPE_OSAGO) {
            $inviteDate = $this->getDateForOsago($carId);
        } else {
            ExceptionCatcher::process(
                new \Exception(trans('messages.absent_packet_calculation_type')),
                trans('messages.absent_packet_calculation_type')
            );
            return [
                'message' => trans('messages.absent_packet_calculation_type'),
            ];
        }

        $descr = '';
        $extraDescr = '';
        $result = [];
        if (isset($inviteDate['date'])) {
            $descr = 'success';
            if (is_array($inviteDate['date'])) {
                $result = [
                    'scheduled_from' => $inviteDate['date'][0]->toAtomString(),
                    'scheduled_to' => $inviteDate['date'][1]->toAtomString(),
                ];
                $extraDescr = $inviteDate['date'][0]->toAtomString() . ' - ' . $inviteDate['date'][1]->toAtomString();
            } else if ($inviteDate['date'] instanceof Carbon) {
                $result = [
                    'scheduled_from' => $inviteDate['date']->startOfDay()->toAtomString(),
                    'scheduled_to' => $inviteDate['date']->endOfDay()->toAtomString(),
                ];
                $extraDescr = $inviteDate['date']->toAtomString();
            }
        } else if (isset($inviteDate['message']) && strlen($inviteDate['message']) > 0) {
            $descr = 'message - ' . $inviteDate['message'];
            \Log::error($inviteDate['message']);
            $result = [
                'message' => $inviteDate['message'],
            ];
        }
        $client = $this->clientRepo->get(new Criteria('id', $clientId));
        $this->angaraRequests->sendEvent([
            'eventName' => 'preReserveInviteDateCalc',
            'clientPhone' => $client['phone'],
            'eventDescr' => $descr,
            'eventExtraDescr' => $extraDescr,
            'company' => [
                'id' => $companyId,
                'name' => $companyName,
            ]
        ]);
        $this->statistics->sendCarInviteDateStatisticToQueue($carId, $clientId, $packet['name'], $result);

        return $result;
    }

    private function getDateForMileage(int $carId): array {
        $carOil = $this->carOilRepo->get(
            new Criteria('car_id', $carId)
        );
        if ($carOil->isEmpty()) {
            return [
                'date' => null,
                'message' => 'Нет данных по маслу',
            ];
        }
        if ($carOil['next_time'] !== null) {
            $inviteDate = Carbon::parse($carOil['next_time']);
            $inviteDateClone = clone $inviteDate;
            if ($inviteDate->isAfter(Carbon::now())) {
                return [
                    'date' => $carOil['is_exactly_next_time'] === 1
                        ? $inviteDate
                        : [
                            $inviteDateClone->startOfMonth(),
                            $inviteDate->endOfMonth(),
                        ],
                ];
            }
        }
        $carOilMonths = $this->carOilMonthsRepo->getMany(
            new Criteria('car_id', $carId)
        )['items']->toArray();

        // По месяцам
        if (count($carOilMonths) > 0) {
            $months = [];
            foreach($carOilMonths as $carOilMonth) {
                $months[] = $carOilMonth['month'];
            }
            asort($months);
            $nextOilChangeMonth = null;
            $now = Carbon::now();
            $currentMonth = $now->month;
            foreach ($months as $month) {
                if ($month >= $currentMonth) {
                    $nextOilChangeMonth = $month;
                    break;
                }
            }
            $nextOilChangeMonth === null
                ? $now->addYear()->month($months[0])
                : $now->month($nextOilChangeMonth);
            $nowClone = clone $now;

            return [
                'date' => [
                    $nowClone->startOfMonth(),
                    $now->endOfMonth(),
                ],
            ];
        }

        // По пробегу
        $minMax = collect($this->carSQL->getMileagesMinMax($carId))
            ->map(function ($car) {
                $car['date'] = Carbon::parse($car['date']);
                return (object)$car;
            });
        $averageMileage = $this->mileageStatisticLibrary->averageMileage($minMax);
        $dayAverageMileage = isset($averageMileage['days']) ? $averageMileage['days'] : null;
        if ($dayAverageMileage === null) {
            return [
                'date' => null,
                'message' => 'Нет данных по пробегу',
            ];
        }
        $message = null;
        $date = null;
        /** @var App\Database\Models\Car\Mileage $lastMileage */
        $lastMileage = Mileage::where('car_id', $carId)
            ->orderByDesc('created_at')
            ->limit(1)
            ->first();
        if ($lastMileage !== null && $carOil['mileage_next_change'] !== null) {
            $daysBeforeOilChange = ceil(($carOil['mileage_next_change'] - $lastMileage->value) / $dayAverageMileage);
            $inviteDate = Carbon::parse($lastMileage->created_at)
                ->addDays($daysBeforeOilChange);
            if ($inviteDate->isBefore(Carbon::now())) {
                $message = 'Предполагаемый пробег уже пройден';
            } else {
                $inviteDateClone = clone $inviteDate;
                $date = [
                    $inviteDate->startOfMonth(),
                    $inviteDateClone->endOfMonth(),
                ];
            }
        } else if ($carOil['mileage_next_change'] === null) {
            $message = 'Не указан пробег следующей замены масла';
        } else {
            $message = 'Предполагаемая дата прошла';
        }

        $result = [
            'date' => $date,
        ];
        if ($message !== null) {
            $result['message'] = $message;
        }

        return $result;
    }

    private function getDateForSeason(int $carId): array { // ToDo выбираем первое? всегда? а на другой сезон?
        $seasonalPreferences = SeasonalPreference::select('season_period_times.date_start', 'season_period_times.date_end')
            ->where('car_id', $carId)
            ->join('season_period_times', function ($join) {
                $join->on('car_seasonal_preferences.season_id', '=', 'season_period_times.season_id');
                $join->on('car_seasonal_preferences.period_id', '=', 'season_period_times.season_period_id');
            })
            ->orderBy('season_period_times.date_start')
            ->get()
            ->toArray();

        if (count($seasonalPreferences) === 0) {
            return [
                'date' => null,
                'message' => 'Не выбрано намерение произвести шиномонтаж',
            ];
        }
        foreach ($seasonalPreferences as $seasonalPreference) {
            if (Carbon::parse($seasonalPreference['date_start'])->isAfter(Carbon::now())) {
                return [
                    'date' => [
                        Carbon::parse($seasonalPreference['date_start']),
                        Carbon::parse($seasonalPreference['date_end']),
                    ]
                ];
            }
        }

        return [
            'date' => null,
            'message' => 'Начало сезона прошло',
        ];
    }

    private function getDataForCasco(int $carId): array {
        $car = Car::where('id', $carId)
            ->with('insuranceinfo')
            ->first();
        if ($car === null) {
            return [
                'date' => null,
                'message' => 'Автомобиль не найден',
            ];
        }
        if ($car['insuranceinfo'] !== null && $car['insuranceinfo']['date_end_casco'] !== null) {
            $dateEnd = Carbon::parse($car['insuranceinfo']['date_end_casco']);
            if ($dateEnd->isAfter(Carbon::now())) {
                return [
                    'date' => $dateEnd,
                ];
            }

            return [
                'date' => null,
                'message' => 'Дата окончания КАСКО прошла',
            ];
        }

        return [
            'date' => null,
            'message' => 'Нет данных по КАСКО',
        ];
    }

    private function getDateForOsago(int $carId): array {
        $car = Car::where('id', $carId)
            ->first();
        if ($car === null) {
            return [
                'date' => null,
                'message' => 'Автомобиль не найден',
            ];
        }
        if ($car['insurance_deadline'] !== null) {
            $dateEnd = Carbon::parse($car['insurance_deadline']);
            if ($dateEnd->isAfter(Carbon::now())) {
                return [
                    'date' => $dateEnd,
                ];
            }

            return [
                'date' => null,
                'message' => 'Дата окончания ОСАГО прошла',
            ];
        }

        return [
            'date' => null,
            'message' => 'Нет данных по ОСАГО',
        ];
    }
}
