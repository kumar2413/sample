<?php

namespace test\Http\Controllers\API;

use \App;
use Carbon\Carbon;
use \Log;
use test\Helpers\SSResponse;
use test\Http\Controllers\BaseController;
use test\Models\Eloquent\Agency;
use test\Models\Eloquent\Broker;
use test\Models\Eloquent\Eoi;
use test\Models\Eloquent\ProjectBroker;
use test\Models\Eloquent\Property;
use test\Models\Eloquent\PropertyDocument;
use test\Models\Eloquent\Sale;
use test\Models\Eloquent\User;
use test\Models\Eloquent\UserPushNotificationRegister;
use test\Models\Fields\EoiBrokerFields;
use test\Models\Fields\EoiFields;
use test\Models\Fields\OfferFields;
use test\Models\Eloquent\Unit;
use test\Models\Eloquent\Offer;
use test\Models\Fields\PaymentFields;
use test\Models\Eloquent\Payment;
use test\Models\Fields\SaleBuyerFields;
use test\Models\Fields\SaleFields;
use test\Repositories\Eloquent\PaymentRepository;
use test\Services\AgencyService;
use test\Services\AgencyUnitCommissionService;
use test\Services\APNSService;
use test\Services\BrokerService;
use test\Services\OfferService;
use test\Services\PromotionCodeService;
use test\Services\SaleBuyerService;
use test\Services\SaleService;
use test\Services\SaleDocumentService;
use test\Services\UnitService;
use test\Services\PropertyService;
use test\Models\Fields\SaleHistoryFields;



class MyAPIController extends BaseController
{

    const SALE_DATA = 'sale_data';

    /** @param  OfferService */
    private $saleBuyerService;
    private $saleDocumentService;
    private $saleService;
    private $unitService;
    private $offerService;
    private $agencyService;
    private $brokerService;
    private $agencyUnitCommissionService;
    private $apnsService;
    private $promotionCodeService;
    private $propertyService;

    public function __construct(
        SaleBuyerService $saleBuyerService,
        SaleDocumentService $saleDocumentService,
        SaleService $saleService,
        UnitService $unitService,
        PropertyService $propertyService,
        OfferService $offerService,
        PaymentRepository $paymentRepository,
        AgencyService $agencyService,
        BrokerService $brokerService,
        AgencyUnitCommissionService $agencyUnitCommissionService,
        APNSService $apnsService,
        PromotionCodeService $promotionCodeService
    )
    {
        $this->saleBuyerService = $saleBuyerService;
        $this->saleDocumentService = $saleDocumentService;
        $this->saleService = $saleService;
        $this->unitService = $unitService;
        $this->propertyService = $propertyService;
        $this->offerService = $offerService;
        $this->paymentRepository = $paymentRepository;
        $this->agencyService = $agencyService;
        $this->brokerService = $brokerService;
        $this->agencyUnitCommissionService = $agencyUnitCommissionService;
        $this->apnsService = $apnsService;
        $this->promotionCodeService = $promotionCodeService;
    }

    // START: SALE SESSION ======================
    public function method1()
    {
        $user = getAPIUser();

        // Only Broker
        if (!($this->isBroker() && $user->getBroker())) {
            ssAbort2(ERROR_CODE_UNAUTHORIZED, 401004);
        }

        // Params validation
        $params = \Request::all();

        // Validations
        $validations = [
            'eoi_id' => 'required|int|exists:eoi,id',
            'unit_id' => 'required|int|exists:units,id',
            'offer_price' => 'int',
        ];
        $validator = \Validator::make($params, $validations);
        if ($validator->fails()) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, $validator->errors());
        }

        // Get params
        $eoiId = array_get($params, 'eoi_id');
        $unitId = array_get($params, 'unit_id');

        $offerPrice = array_get($params, 'offer_price');

        // Current broker should be main broker of this EOI
        $eoi = Eoi::whereId($eoiId)
            ->whereHas('brokers', function($query) use($user) {
                $query->whereBrokerUserId($user->id);
            })
            ->first();
        if (!$eoi) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 305004);
        }
        if ($eoi->status != EOI_STATUS_SUCCESSFUL) {
            ssAbort2(ERROR_CODE_FORBIDDEN, 305009);
        }

        $unit = null;
        $selectedUnits = json_decode($eoi->selected_units);
        foreach ($selectedUnits as $selectedUnit) {
            $_unitId = $selectedUnit[0];
            $_unitStatus = $selectedUnit[1];

            if ($_unitId == $unitId) {
                if ($_unitStatus == EOI_SELECTED_UNIT_STATUS_COMPLETED) {
                    ssAbort2(ERROR_CODE_CONFLICT, 305005);
                }

                $unit = $this->unitService->findUnit($unitId, $eoi->property_id);
            }
        }

        if (!$unit) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 305001);
        }

        // Open session
        $status = $this->saleService->sessionOpen($user, $eoiId, $unitId, $offerPrice);

        $data = [];

        if ($status == SaleService::CODE_SUCCESS) {
            $sale = $this->saleService->findSaleByUnitId($user, $unitId);
            if ($sale) {
                $sale->load([
                    'saleBuyers',
                    'questionAnswers',
                    'brokers',
                    'documents',
                    'documents.document',
                    'documents.document.documentSignees',
                ]);

                $sale->addField('sale_buyers', $sale->saleBuyers);
                $sale->addField('question_answers', $sale->questionAnswers);
                $sale->loadField('brokers');

                if ($sale->documents) {
                    foreach ($sale->documents as $saleDocument) {
                        if ($saleDocument->document) {
                            $saleDocument->document->addField(
                                'document_signees',
                                $saleDocument->document->documentSignees
                            );
                            $saleDocument->loadField('document');
                        }
                    }
                    $sale->loadField('documents');
                }
            }
            $data = [
                'sale' => $sale,
            ];
        }

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data, $status, $this->getErrorMessage($status));
    }

    public function method2()
    {
        $user = getAPIUser();

        $params = \Request::instance()->all();
        $unitId = array_get($params, 'unit_id');

        if (empty($unitId))
        {
            ssAbort("unit_id is required", ERROR_CODE_PARAM_INVALID);
        }

        $status = $this->saleService->sessionPing($user, $unitId);

        if ($status == 0)
        {
            return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS);
        }

        return SSResponse::getJsonResponse(false, ERROR_CODE_FORBIDDEN, [], $status, $this->getErrorMessage($status));
    }

    public function method3()
    {
        $user = getAPIUser();

        $params = \Request::instance()->all();
        $unitId = array_get($params, 'unit_id');

        if (empty($unitId)) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                'unit_id' => ['Unit id is required.'],
            ]);
        }

        $status = $this->saleService->sessionRefresh($user, $unitId, false);
        if (!$status) {
            ssAbort2(ERROR_CODE_FORBIDDEN, 305011);
        }

        $unit = $this->unitService->findUnit($unitId);
        if (!$unit) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 305001);
        }

        // Check the time as given in property launch settings
        $sale = $this->saleService->findSaleByUnitId($user, $unitId);
        if ($sale
            && $sale->eoi
            && $sale->eoi->propertyLaunch
            && $sale->eoi->propertyLaunch->booking_session_minutes
        ) {
            $bookingSessionMinutes = $sale->eoi->propertyLaunch->booking_session_minutes;
        } else {
            $bookingSessionMinutes = \Config::get('app.booknow_session_minutes');
        }

        $diff = $unit->reserved_at->diff(Carbon::now());
        $diffMinutes = $diff->i > 0 ? $diff->i : 0;
        $remainingMinutes = $bookingSessionMinutes > $diffMinutes ? $bookingSessionMinutes - $diffMinutes : 0;

        $data = [
            'unit_reserved_at' => $unit->reserved_at->toDateTimeString(),
            'booknow_session_minutes' => $bookingSessionMinutes,
            'session_remaining_minutes' => $remainingMinutes,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method4()
    {
        $user = getAPIUser();

        $status = $this->saleService->sessionClose($user);

        if ($status == 0)
        {
            return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS);
        }

        return SSResponse::getJsonResponse(false, ERROR_CODE_FORBIDDEN, [], $status, $this->getErrorMessage($status));
    }

    // END: SALE SESSION ========================

    public function method5()
    {

        $user = getAPIUser();

        // TODO: Buyer can access sales at any stage.
        // TODO: Team Lead can access sales after SUBMITTED.
        // TODO: Developer needs to call APIs from DevSaleAPIController.

        $params = \Request::all();
        $offset = filter_var(array_get($params, self::PARAM_OFFSET, 0), FILTER_VALIDATE_INT);
        $limit = filter_var(array_get($params, self::PARAM_LIMIT, 10), FILTER_VALIDATE_INT);
        $sendCount = filter_var(array_get($params, self::PARAM_SEND_TOTAL_COUNT), FILTER_VALIDATE_BOOLEAN);

        $propertyId = array_get($params, 'property_id');
        $unitNo = array_get($params, 'unit_no');
        $eoiNo = array_get($params, 'eoi_no');
        $agency = array_get($params, 'agency');
        $broker = array_get($params, 'broker');
        $saleBuyer = array_get($params, 'buyer');
        $otpNo = array_get($params, 'otp_no');
        $price = array_get($params, 'price');
        $statuses = array_get($params, SaleFields::STATUS);

        $order = array_get($params, 'order');



        //$propertyId = array_get($params, 'property_id');
        $propertyLaunchId = array_get($params, 'property_launch_id');
        $statuses = array_filter(array_map('intval', explode(',', $statuses)));

        $query = Sale::query();


        ###################

        // Property Filter
        if ($propertyId) {

            $query->whereHas('unit', function ($query) use ($propertyId) {
                $query->wherePropertyId($propertyId);
            });
        }

        // Unit filter
        if ($unitNo) {
            if (strpos($unitNo, '-') === false) {
                ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                    'unit_no' => ['Invalid unit no value.'],
                ]);
            }

            // Remove # if exists
            $unitNo = substr($unitNo, 0, 1) == '#' ? substr($unitNo, 1) : $unitNo;

            $unitNoArray = explode('-', $unitNo);
            if (sizeof($unitNoArray) != 2) {
                ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                    'unit_no' => ['Invalid unit no value.'],
                ]);
            }

            $_floorNo = intval($unitNoArray[0]);
            $_unitNo = intval($unitNoArray[1]);

            if (!($_floorNo && $_unitNo)) {
                ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                    'unit_no' => ['Invalid unit no value.'],
                ]);
            }

            $query->whereHas('unit', function ($query) use ($_floorNo, $_unitNo) {
                $query->whereFloorNo($_floorNo)
                    ->whereUnitNo($_unitNo);
            });
        }

        // Eoi Filter
        if ($eoiNo) {
            $query->whereHas('eoi', function ($query) use ($eoiNo) {
                $query->whereEoiNo($eoiNo);
            });
        }

        if ($agency) {
            $query->whereHas('brokers', function ($query) use ($agency) {
                $query->whereBroker_role('1');
                $query->whereHas('broker', function ($query) use ($agency) {
                    $query->whereHas('agency', function ($query) use ($agency) {
                        $query->where('name', 'like', '%'.$agency.'%');
                    });
                });

            });
        }

        if ($broker) {
            $query->whereHas('brokers', function ($query) use ($broker) {
                $query->whereBroker_role('1');
                $query->whereHas('broker', function ($query) use ($broker) {
                    $query->whereHas('user', function ($query) use ($broker) {
                        $query->where(\DB::raw('CONCAT_WS(" ", first_name, last_name)'), 'like', '%'.trim($broker).'%');
                    });
                });
            });
        }

        if ($propertyLaunchId) {
            $query->whereHas('eoi', function ($query) use ($propertyLaunchId) {
                $query->wherePropertyLaunchId($propertyLaunchId);
            });
        }

        // OTP No Filter
        if ($otpNo) {
            $query->whereOtpNo($otpNo);
        }

        if ($price) {
            $query->wherePrice($price);
        }

        // Sort
        if ($order) {
            $orders = is_string($order) ? json_decode($order) : $order;
            foreach ($orders as $_field => $_order) {
                if (!in_array($_field, [
                    SaleFields::AGENCY_ID,
                    SaleFields::EOI_ID,
                    SaleFields::UNIT_ID,
                    SaleFields::PRICE,
                    SaleFields::STATUS,
                    SaleFields::OTP_NO,
                    SaleFields::OTP_DATE,
                    SaleFields::SPA_DATE,
                    SaleFields::SPA_DELIVERY_DATE,
                    SaleFields::EXERCISE_DATE,
                    SaleFields::OTP_ABORTED_DATE,
                    SaleFields::EXPIRY_DATE,
                    SaleFields::CREATED_BY,
                    SaleFields::CREATED_AT,
                    SaleFields::UPDATED_AT,
                ])) {
                    continue;
                }
                if (!in_array(strtolower($_order), [
                    'asc',
                    'desc',
                ])) {
                    continue;
                }
                $query->orderBy($_field, $_order);
            }
        } else {
            $query->orderBy(SaleFields::UPDATED_AT, 'desc');
        }

        ###################


        // Buyer
        if ($this->isCustomer()) {

            $buyerIds = [];
            if ($user->buyers) {
                foreach ($user->buyers as $buyer) {
                    $buyerIds[] = $buyer->id;
                }
            }

            if ($buyerIds) {
                //$query = Sale::whereHas('saleBuyers', function ($query) use ($buyerIds) {
                $query->whereHas('saleBuyers', function ($query) use ($buyerIds) {
                    $query->whereIn(SaleBuyerFields::BUYER_ID, $buyerIds);
                });
            }
        }

        // Broker
        // For normal broker, can access all the sales that he/she created
        // Team lead can access all sales submitted by brokers from his/her agency
        if ($this->isBroker()) {

            $broker = $user->broker;
            // Check if this broker is a Team Lead in any project
            $projectBrokers = ProjectBroker::whereBrokerUserId($broker->user_id)
                ->whereProjectBrokerType(PROJECT_BROKER_TYPE_TEAMLEAD)
                ->get();
            if ($projectBrokers && !$projectBrokers->isEmpty()) {
                foreach ($projectBrokers as $projectBroker) {
                    $agencyProject = $projectBroker->project;
                    $query->where(function($query) use ($broker) {
                        $query->whereAgencyId($broker->agency_id);
                        $query->orWhereHas('brokers', function ($query) use ($broker) {
                            $query->whereBrokerUserId($broker->user_id);
                        });
                    });
                }
            }
            else{
                $query->whereHas('brokers', function ($query) use ($broker) {
                    $query->whereBrokerUserId($broker->user_id);
                });
            }
            $allowedStatuses = [
                SALE_STATUS_SUBMITTED,
                SALE_STATUS_VERIFIED,
                SALE_STATUS_APPROVED,
                SALE_STATUS_REJECTED,

                SALE_STATUS_OTP_GENERATED,
                SALE_STATUS_CHEQUE_ENTERED,
                SALE_STATUS_SPA_ENTERED,
                SALE_STATUS_EXPIRED,
                SALE_STATUS_EXTENDED,
                SALE_STATUS_ABORTED,
                SALE_STATUS_INVALID,
                SALE_STATUS_DONE,
            ];
            if ($statuses) {
                $statuses = array_intersect($allowedStatuses, $statuses);
            } else {
                $statuses = $allowedStatuses;
            }
            $query->whereIn(SaleFields::STATUS, $statuses);
        }
        $totalCount = null;
        if ($sendCount) {
            $totalCount = $query->get()->count();
        }
        if ($offset) {
            $query->skip($offset);
        }
        if ($limit) {
            $query->take($limit);
        }
        $sales = $query->get();

        if(count($sales)){
            if(!isset($sales[0]) || !is_object($sales[0])){
                ssAbort2(ERROR_CODE_NOT_FOUND, 412001, [
                    '' => ['Search not found.'],
                ]);
            }
        }

        // Response data

        $data = $this->prepareSalesResponse($sales);
        $data['total_count'] = $totalCount;


        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method6($saleId)
    {
        $user = getAPIUser();

        // TODO: Buyer can access sales at any stage.
        // TODO: Team Lead can access sales after SUBMITTED.
        // TODO: Developer needs to call APIs from DevSaleAPIController.

        // Fetch Objects
        $sale = Sale::whereId($saleId)->first();
        if (!$sale) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 305010);
        }

        // Buyer
        if ($this->isCustomer()) {
            $hasAccess = false;

            $buyerIds = [];
            if ($user->buyers) {
                foreach ($user->buyers as $buyer) {
                    $buyerIds[] = $buyer->id;
                }
            }

            if ($buyerIds) {
                foreach ($sale->saleBuyers as $saleBuyer) {
                    if ($saleBuyer->buyer_id && in_array($saleBuyer->buyer_id, $buyerIds)) {
                        $hasAccess = true;
                        break;
                    }
                }
            }

            if (!$hasAccess) {
                ssAbort2(ERROR_CODE_UNAUTHORIZED, 305013);
            }
        }

        // Broker
        // For normal broker, can access all the sales that he/she created
        // Team lead can access all sales submitted by brokers from his/her agency
        if ($this->isBroker()) {
            $broker = $user->broker;

            $hasAccess = false;

            // Check broker's own sales
            if ($sale->created_by == $broker->user_id) {

                $hasAccess = true;

            } else {

                $allowedStatuses = [
                    SALE_STATUS_SUBMITTED,
                    SALE_STATUS_VERIFIED,
                    SALE_STATUS_APPROVED,
                    SALE_STATUS_REJECTED,

                    SALE_STATUS_OTP_GENERATED,
                    SALE_STATUS_CHEQUE_ENTERED,
                    SALE_STATUS_SPA_ENTERED,
                    SALE_STATUS_EXPIRED,
                    SALE_STATUS_EXTENDED,
                    SALE_STATUS_ABORTED,
                    SALE_STATUS_INVALID,
                    SALE_STATUS_DONE,
                ];

                if (in_array($sale->status, $allowedStatuses)) {

                    // Check if this broker is a Team Lead in any project
                    $projectBroker = ProjectBroker::whereBrokerUserId($broker->user_id)
                        ->whereProjectBrokerType(PROJECT_BROKER_TYPE_TEAMLEAD)
                        ->whereHas('project', function ($query) use ($sale) {
                            $query->wherePropertyId($sale->unit->property_id);
                        })
                        ->first();

                    if ($projectBroker) {
                        $hasAccess = true;
                    }
                }
            }

            if (!$hasAccess) {
                ssAbort2(ERROR_CODE_UNAUTHORIZED, 305013);
            }
        }

        $data = $this->prepareSalesResponse([$sale]);

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    private function method7($sales)
    {
        $params = \Request::all();

        $include = array_get($params, 'include');
        $include = array_filter(array_map('trim', explode(',', $include)));

        $data = [
            'sales' => $sales,
        ];

        $eoiIds = [];
        $propertyIds = [];
        $unitIds = [];
        $brokerUserIds = [];
        $agencyIds = [];
        $userIds = [];

        foreach ($sales as $sale) {
            $sale->load([
                'unit',
                'saleBuyers',
                'questionAnswers',
                'brokers',
                'documents',
                'documents.document',
                'documents.document.documentSignees',
            ]);

            if (in_array('eois', $include)) {
                $eoiIds[] = $sale->eoi_id;
            }
            if (in_array('properties', $include)) {
                $propertyIds[] = $sale->unit->property_id;
            }
            if (in_array('units', $include)) {
                $unitIds[] = $sale->unit_id;
            }
            if (in_array('brokers', $include)) {
                $sale->loadField('brokers');
                foreach ($sale->brokers as $saleBroker) {
                    $brokerUserIds[] = $saleBroker->broker_user_id;
                }
            }
            if (in_array('sale_buyers', $include)) {
                $sale->addField('sale_buyers', $sale->saleBuyers);
            }
            if (in_array('question_answers', $include)) {
                $sale->addField('question_answers', $sale->questionAnswers);
            }
            if (in_array('documents', $include)) {
                $sale->loadField('documents');
            }
            if (in_array('payments', $include)) {
                foreach ($sale->payments as $payment) {
                    $payment->loadField('instruments');
                }
                $sale->loadField('payments');
            }
            if (in_array('history', $include)) {
                $sale->loadField('history');

                if ($sale->history) {
                    foreach ($sale->history as $historyItem) {
                        if (!in_array($historyItem->created_by, $userIds)) {
                            $userIds[] = $historyItem->created_by;
                        }
                    }
                }
            }
        }

        // Load objects
        if ($eoiIds) {
            $eoiIds = array_unique($eoiIds);
            $data['eois'] = Eoi::whereIn('id', $eoiIds)->get();
        }
        if ($propertyIds) {
            $propertyIds = array_unique($propertyIds);
            $data['properties'] = Property::whereIn('id', $propertyIds)->get();
        }
        if ($unitIds) {
            $unitIds = array_unique($unitIds);
            $data['units'] = Unit::whereIn('id', $unitIds)->get();
        }
        if ($brokerUserIds) {
            $brokerUserIds = array_unique($brokerUserIds);
            $brokers = Broker::whereIn('user_id', $brokerUserIds)->get();
            foreach ($brokers as $broker) {
                $broker->loadField('user');
                if (!in_array($broker->user_id, $userIds)) {
                    $userIds[] = $broker->user_id;
                }

                if (in_array('agencies', $include)) {
                    $agencyIds[] = $broker->agency_id;
                }
            }
            $data['brokers'] = $brokers;
        }
        if ($agencyIds) {
            $agencyIds = array_unique($agencyIds);
            $data['agencies'] = Agency::whereIn('id', $agencyIds)->get();
        }

        if ($userIds) {
            $userIds = array_unique($userIds);
            $users = User::whereIn('id', $userIds)->get();

            $data['users'] = $users;
        }

        return $data;
    }

    public function method8($saleId)
    {
        $user = getAPIUser();

        //---------------------------------------
        // Fetch Objects
        $sale = $this->saleService->findSaleById($saleId);
        if (!$sale) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 305010);
        }
        if ($sale->created_by != $user->id) {
            ssAbort2(ERROR_CODE_UNAUTHORIZED, 305011);
        }
        //---------------------------------------

        // TODO: May get these params from request
        $sale = $this->saleService->initSale($user, $sale->eoi_id, $sale->unit_id);

        $sale->addField('sale_buyers', $sale->saleBuyers);
        $sale->addField('question_answers', $sale->questionAnswers);
        $sale->loadField('brokers');

        foreach ($sale->documents as $saleDocument) {
            if ($saleDocument->document) {
                $saleDocument->document->addField('document_signees', $saleDocument->document->documentSignees);
            }
            $saleDocument->loadField('document');
        }
        $sale->loadField('documents');

        $data = [
            'sale' => $sale,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method9($saleId)
    {
        $user = getAPIUser();
        //---------------------------------------
        // Validations
        $params = \Request::all();

        $validator = \Validator::make($params, [
            SaleFields::STATUS => 'required|integer',
            SaleFields::REMARKS => 'max:255',
        ]);
        if ($validator->fails()) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, $validator->errors());
        }
        //---------------------------------------
        // Params
        $status = array_get($params, 'status');
        $remarks = array_get($params, 'remarks');
        //---------------------------------------
        // Fetch Objects
        $sale = $this->saleService->findSaleById($saleId);
        if (!$sale) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 305010);
        }
        if ($sale->status == $status) {
            ssAbort2(ERROR_CODE_UNAUTHORIZED, 412002, [
                SaleFields::STATUS => ['No change found.'],
            ]);
        }
        //---------------------------------------

        // TODO: User validations. Who can do what?
        // TODO: Status checks. What status can be changed to what?
        // TODO: Sale prerequisite checks. Is sale ready to be submitted? Documents signed etc.
        // TODO: Remarks concatenation

        if ($status == SALE_STATUS_SUBMITTED || $status == SALE_STATUS_PENDING) {
            // The one who submitted/created this sale
            if ($sale->created_by != $user->id) {
                ssAbort2(ERROR_CODE_UNAUTHORIZED, 305011);
            }
            $this->saleService->saleSubmit($user, $sale, $params);
        } else if ($status == SALE_STATUS_VERIFIED) {
            // TODO: Team Lead

            $this->saleService->saleVerify($user, $sale, $params);
        } else if ($status == SALE_STATUS_REJECTED) {
            // TODO: Team Lead or Developer

            $this->saleService->saleReject($user, $sale, $params);
//        } else if ($status == SALE_STATUS_APPROVED) {
//            // TODO: Developer
//
//            $this->saleService->saleApprove($user, $sale, $params);
        } else {
            ssAbort2(ERROR_CODE_INTERNAL_SERVER_ERROR, 500001);
        }

        $data = [
            'sale' => $sale,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method10()
    {
        $average_psf = 0; //Excluding Shop
        $total_sqft = 0;
        $total_price = 0;

        $user = getAPIUser();
        $request = \Request::instance();

        $inputData = getRequestBody($request, true);

        $own_hdb = 0;
        $own_other_property = 0;


        $unit_id = getRequiredValFromArray($inputData, 'unit_id');
        $agency_id = getRequiredValFromArray($inputData, 'agency_id');
        $broker_user_id = getRequiredValFromArray($inputData, 'broker_user_id');
        $co_broker_user_id = array_get($inputData, 'co_broker_user_id');
        $others = getRequiredValFromArray($inputData, 'others');
        if ($others['own_hdb'] == 'yes')
            $own_hdb = 1;
        if ($others['own_other'] == 'yes')
            $own_other_property = 1;

        $remarks = array_get($inputData, 'remarks');

        // Broker validations
        if (empty($co_broker_user_id)) {
            $mainBroker = $this->brokerService->findBroker($broker_user_id);
            if (!$mainBroker || $mainBroker->agency_id != $agency_id) {
                ssAbort("Invalid Broker", ERROR_CODE_PARAM_INVALID, 0, "");
            }
        } else {
            $mainBroker = $this->brokerService->findBroker($broker_user_id);
            $coBroker = $this->brokerService->findBroker($co_broker_user_id);
            if (!$mainBroker) {
                ssAbort("Invalid Broker", ERROR_CODE_PARAM_INVALID, 0, "");
            }
            if (!$coBroker || $coBroker->agency_id != $agency_id) {
                ssAbort("Invalid Broker", ERROR_CODE_PARAM_INVALID, 0, "");
            }
        }

        // Unit Validations =====================
        $unit = $this->unitService->findUnit($unit_id);
        if (empty($unit)) {
            return SSResponse::getJsonResponse(false, ERROR_CODE_NOT_FOUND, null, 208, "Invalid Unit input");
        } else if ($unit->status == UNIT_STATUS_LISTED) {
            if ($unit->price_type == UNIT_PRICE_TYPE_FIXED) {
                return SSResponse::getJsonResponse(false, ERROR_CODE_FORBIDDEN, null, 408, "No active session found");
            }
        } else if ($unit->status == UNIT_STATUS_RESERVED || $unit->status == UNIT_STATUS_SPECIAL_RESERVED) {
            if ($this->saleService->sessionPing($user, $unit->id) != SaleService::CODE_SUCCESS) {
                return SSResponse::getJsonResponse(false, ERROR_CODE_FORBIDDEN, null, 208, "Invalid active session or unit is already reserved by another buyer");
            }
        } else if ($unit->status == UNIT_STATUS_BOOKED) {
            return SSResponse::getJsonResponse(false, ERROR_CODE_FORBIDDEN, null, 408, "Already Reserved");
        } else if ($unit->status == UNIT_STATUS_SOLD) {
            return SSResponse::getJsonResponse(false, ERROR_CODE_FORBIDDEN, null, 208, "This unit is Sold");
        } else {
            return SSResponse::getJsonResponse(false, ERROR_CODE_INTERNAL_SERVER_ERROR, null, 406, "Cannot process Request");
        }
        // ======================================

        $saleStatus = SALE_STATUS_OTP_GENERATED;
        if ($unit->price_type == UNIT_PRICE_TYPE_NEGOTIABLE) {
            $saleStatus = SALE_STATUS_PENDING; // It is an offer
        }

        // Commissions
        $agencyProject = $this->agencyService->getAgencyProjectByAgencyIdAndPropertyId($agency_id, $unit->property_id);
        if (!$agencyProject) {
            ssAbort("Invalid Agency", ERROR_CODE_PARAM_INVALID, 0, "");
        }
        $agencyCommission = $agencyProject->agency_commission;
        $teamleadCommission = $agencyProject->teamlead_commission;
        $brokerCommission = $agencyProject->broker_commission;
        $coBrokerCommission = $co_broker_user_id ? $agencyProject->cobroker_commission : 0;

        $agencyUnitCommission = $this->agencyUnitCommissionService->getAgencyUnitCommission([
            "unit_id" => $unit_id,
            "agency_id" => $agency_id,
        ]);
        if ($agencyUnitCommission) {
            $agencyCommission = $agencyUnitCommission->agency_commission;
        }

        $projectBroker = $this->brokerService->getProjectBrokerByProjectIdAndBrokerId(
            $agencyProject->id, $co_broker_user_id ? $co_broker_user_id : $broker_user_id
        );
        if (!$projectBroker) {
            ssAbort("Invalid Broker", ERROR_CODE_PARAM_INVALID, 0, "");
        } else if ($projectBroker->commission > 0) {
            $brokerCommission = $projectBroker->commission;
        }

        // Sale
        $sale = $this->saleService->findSaleByUnitId($user, $unit->id);
        if (!$sale) {
            $sale = new Sale();
        }

        // Check Documents Signature
        if (!$this->saleService->isAllSaleDocumentsSigned($sale)) {
            $saleStatus = SALE_STATUS_PENDING; // Keep this pending till all buyers sign all the documents
        }

        $sale->agency_id = $agency_id;
        $sale->broker_user_id = $broker_user_id;
        $sale->co_broker_user_id = $co_broker_user_id;
        $sale->own_hdb = $own_hdb;
        $sale->own_other_property = $own_other_property;

        $sale->agency_commission = $agencyCommission;
        $sale->teamlead_commission = $teamleadCommission;
        $sale->broker_commission = $brokerCommission;
        $sale->cobroker_commission = $coBrokerCommission;


        $sale->status = $saleStatus;

        $sale->created_by = $user->id;
        $sale->remarks = $remarks;

        if ($unit->price_type == UNIT_PRICE_TYPE_FIXED) {
            $sale->otp_date = Carbon::now();
        }

        // Transaction Started
        \DB::beginTransaction();

        $sale->save();


        if ($unit->price_type == UNIT_PRICE_TYPE_NEGOTIABLE) {
            // If sales type is NEGOTIABLE then create an offer
            $this->offerService->create([
                OfferFields::SALE_ID => $sale->id,
                OfferFields::PROPERTY_ID => $unit->property_id,
                OfferFields::USER_ID => $sale->customer_user_id,
                OfferFields::UNIT_ID => $sale->unit_id,
                OfferFields::AMOUNT => $sale->price,
                OfferFields::BROKER_USER_ID => $sale->broker_user_id,
                OfferFields::CO_BROKER_USER_ID => $sale->co_broker_user_id,
                OfferFields::DEVELOPER_ID => $sale->developer_id,
                OfferFields::STATUS => Offer::STATUS_NEW,
                OfferFields::REMARKS => $sale->remarks,
            ]);
        } else {
            $unit->status = UNIT_STATUS_BOOKED;
            $unit->reserved_by = null;
            $unit->reserved_at = null;

            $unit->save();

            // Add empty payment with PENDING status
            $this->paymentRepository->create([
                PaymentFields::SALE_ID => $sale->id,
                PaymentFields::DEVELOPER_ID => $sale->developer_id,
                PaymentFields::AGENCY_ID => $sale->agency_id,
                PaymentFields::CUSTOMER_USER_ID => $sale->customer_user_id,
                PaymentFields::TYPE => Payment::STATUS_PENDING,
                PaymentFields::CREATED_BY => $user->id
            ]);
        }

        // Buyer Specific Documents
        $this->saleService->createSaleBuyerDocuments($sale->id);

        \DB::commit();

        // Send App Notifications
        $this->sendSaleNotifications($user, $sale);

        // Send Email Notifications
        //\QueueHelper::sendSaleEmails($sale->id);git diff

        // Check AML
        \QueueHelper::checkAML($sale->id);

        $data = [
            'sale' => [
                'id' => $sale->id,
            ]
        ];

        // TODO: Add status code failure if  NULL
        return SSResponse::getJsonResponse(true, 200, $data);
    }

    public function method112()
    {
        $user = getAPIUser();
        $request = \Request::instance();
        $inputData = getRequestBody($request, true);
        $own_hdb = 0;
        $own_other_property = 0;

        $others = getRequiredValFromArray($inputData, 'others');

        if ($others['own_hdb'] == 'yes')
            $own_hdb = 1;
        if ($others['own_other'] == 'yes')
            $own_other_property = 1;

        $user->own_hdb = $own_hdb;
        $user->own_other_property = $own_other_property;
        $user->save();

        return SSResponse::getJsonResponse(true, 200, []);
    }

    private function method14($user, $sale)
    {
        try {
            $brokerUser = $sale->broker->user;
            if ($brokerUser->mobile_notifications == 1) {
                $message = $user->display_name . " booked the unit # " . $sale->unit->unit_no . " in " . $sale->unit->property->name . " project.";
                if ($sale->status == SALE_STATUS_PENDING) {
                    $message = $user->display_name . " made an offer for unit # " . $sale->unit->unit_no . " in " . $sale->unit->property->name . " project.";
                }

                foreach ($sale->broker->user->userPushNotificationRegisters as $entry) {
                    if ($entry->os_type == UserPushNotificationRegister::OS_TYPE_IOS) {
                        $this->apnsService->sendNotification($entry->device_token, $message);
                    }
                }
            }
        } catch (\Exception $ex) {
            \Log::error($ex->getTraceAsString());
        }
    }

    public function method18()
    {
        $user = getAPIUser();

        $params = \Request::all();
        $unitId = array_get($params, 'unitId', null);

        $unit = $this->unitService->findUnit($unitId);
        if (!$unit) {
            \App::abort(ERROR_CODE_NOT_FOUND, "Unit not found with id $unitId");
        }

        $data = [];
        $data['unit'] = $unit;
        $sale = $this->saleService->findSaleForUnit($unit->id);
        $hasAccess = false;
        $teamLead = false;
        $teamBroker = false;

        if (!empty($sale)) {
            $mainBroker = $this->saleService->getSaleMainBroker($sale->id);
            $saleDetails = $this->prepareSalesResponse([$sale]);

            if ($this->isBroker()) {
                $Broker_types = [
                    PROJECT_BROKER_TYPE_TEAMLEAD,
                    PROJECT_BROKER_TYPE_ASSISTANT_TEAMLEAD,
                    PROJECT_BROKER_TYPE_BROKER,
                    PROJECT_BROKER_TYPE_TAGGER
                ];

                // Check broker role in this unit/property
                $projectBroker = ProjectBroker::whereBrokerUserId($user->id)
                    ->whereIn('project_broker_type', $Broker_types)
                    ->whereHas('project', function ($query) use ($sale) {
                        $query->wherePropertyId($sale->unit->property_id);
                    })
                    ->get();

                foreach($projectBroker as $broker){
                    if($broker->project_broker_type == 1){
                        $teamLead = true;
                    }
                }
                if(!$teamLead && $projectBroker){
                    $teamBroker = true;
                }

                //check if APi user agency and sale agency are same
                if ($user->getBroker()->agency_id == $mainBroker->agency_id) {
                    $hasAccess = true;
                }
            }
            //check if user has access and teamlead or broker
            if ($hasAccess && ($teamBroker || $teamLead)) {
                $data['sale'] = $sale;
                if($teamLead) {
                    $data['broker'] = $mainBroker;
                    $data['agency'] = $sale->agency;
                    $data['sale_details'] = $saleDetails;
                }
            }

            if(!$hasAccess && !$this->isDeveloper())
            {
                $data['unit'] = null;
            }

            if ($this->isDeveloper()) {
                $data['sale'] = $sale;
                $data['broker'] = $mainBroker;
                $data['agency'] = $sale->agency;
                $data['sale_details'] = $saleDetails;

            }
        }



        return SSResponse::getJsonResponse(true, 200, $data);
    }

    /**
     * Add remarks to sales history
     *
     * @param $saleId
     * @param $remarks
     * @return mixed
     * @throws \test\Exceptions\SSException
     */

    public function addRemarks($saleId){


        $user = getAPIUser();

        // Fetch Objects
        $sale = Sale::whereId($saleId)->first();
        if (!$sale) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 305010);
        }

        // Buyer
        if ($this->isCustomer()) {
            $hasAccess = false;

            $buyerIds = [];
            if ($user->buyers) {
                foreach ($user->buyers as $buyer) {
                    $buyerIds[] = $buyer->id;
                }
            }

            if ($buyerIds) {
                foreach ($sale->saleBuyers as $saleBuyer) {
                    if ($saleBuyer->buyer_id && in_array($saleBuyer->buyer_id, $buyerIds)) {
                        $hasAccess = true;
                        break;
                    }
                }
            }


            if (!$hasAccess) {
                ssAbort2(ERROR_CODE_UNAUTHORIZED, 305013);
            }
        }


        if ($this->isBroker()) {
            $broker = $user->broker;

            $hasAccess = false;

            // Check broker's own sales
            if ($sale->created_by == $broker->user_id) {

                $hasAccess = true;

            } else {

                $allowedStatuses = [
                    SALE_STATUS_SUBMITTED,
                    SALE_STATUS_VERIFIED,
                    SALE_STATUS_APPROVED,
                    SALE_STATUS_REJECTED,

                    SALE_STATUS_OTP_GENERATED,
                    SALE_STATUS_CHEQUE_ENTERED,
                    SALE_STATUS_SPA_ENTERED,
                    SALE_STATUS_EXPIRED,
                    SALE_STATUS_EXTENDED,
                    SALE_STATUS_ABORTED,
                    SALE_STATUS_INVALID,
                    SALE_STATUS_DONE,
                ];

                if (in_array($sale->status, $allowedStatuses)) {

                    // Check if this broker is a Team Lead in any project
                    $projectBroker = ProjectBroker::whereBrokerUserId($broker->user_id)
                        ->whereProjectBrokerType(PROJECT_BROKER_TYPE_TEAMLEAD)
                        ->whereHas('project', function ($query) use ($sale) {
                            $query->wherePropertyId($sale->unit->property_id);
                        })
                        ->first();

                    if ($projectBroker) {
                        $hasAccess = true;
                    }
                }
            }

            if (!$hasAccess) {
                ssAbort2(ERROR_CODE_UNAUTHORIZED, 305013);
            }
        }
        $params = \Request::all();

        // Validations
        $validations = [
            SaleHistoryFields::REMARKS => 'max:255',
        ];
        $validator = \Validator::make($params, $validations);
        if ($validator->fails()) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, $validator->errors());
        }
        $remarks = array_get($params, 'remarks');

        $data = $this->saleService->addSaleHistory($sale->id, $sale->status, $remarks);

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);

    }

    private function method21($code)
    {
        $message = '';

        if ($code == ERROR_CODE_SUCCESS) {
            $message = 'Success';
        } else if ($code == SMS_SERVICE_TOKEN_SENT_SUCCESSFULLY) {
            $message = 'SMS OTP sent successfully.';
        } else if ($code == SMS_SERVICE_TOKEN_WAS_ALREADY_SENT) {
            $message = 'SMS OTP is already sent. Please wait or retry after some time.';
        } else if ($code == SMS_SERVICE_TOKEN_SENT_ERROR) {
            $message = 'Unable to send SMS OTP. Please retry after some time and if issue persists, contact support.';
        } else if ($code == SMS_SERVICE_TOKEN_MISSING_REQUIRED_PARAMS) {
            $message = 'Unable to send SMS OTP. Please make sure you have added a valid contact number in your profile.';
        } else if ($code == ERROR_CODE_INTERNAL_SERVER_ERROR) {
            $message = 'Server Error';
        }

        return $message;
    }
}
