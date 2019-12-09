<?php

namespace test\Http\Controllers\API;

use test\Helpers\SSResponse;
use test\Http\Controllers\BaseController;
use test\Models\Eloquent\Buyer;
use test\Models\Eloquent\Eoi;
use test\Models\Eloquent\SaleBuyer;
use test\Models\Eloquent\Unit;
use test\Models\Eloquent\User;
use test\Models\Eloquent\Agency;
use test\Models\Fields\BuyerFields;
use test\Models\Fields\CompanyFields;
use test\Models\Fields\ContactFields;
use test\Models\Fields\EoiFields;
use test\Models\Fields\IndividualFields;
use test\Models\Fields\ProjectBrokerFields;
use test\Models\Fields\PropertyLaunchFields;
use test\Models\Fields\SaleBuyerFields;
use test\Models\Fields\UserBuyerFields;
use test\Models\Fields\UserFields;
use test\Models\Fields\UnitFields;
use test\Models\Fields\AgencyFields;
use test\Services\BuyerService;
use test\Services\ValidationTokenService;
use test\Services\UserRoleService;
use test\Services\UserService;
use test\Models\Fields\AgencyProjectFields;
use Excel;


class MysAPIController extends BaseController
{
    private $buyerService;
    private $userService;
    private $validationTokenService;

    public function __construct(
        BuyerService $buyerService,
        UserService $userService,
        ValidationTokenService $validationTokenService
    )
    {
        $this->buyerService = $buyerService;
        $this->userService = $userService;
        $this->validationTokenService = $validationTokenService;
    }

    // Buyers ----------------------------------------------------------------------------------------------------------

    /**
     * Returns the list of buyers linked to current user
     *
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function getBuyers()
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can access buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        $buyers = null;

        if ($this->isCustomer()) {
            $buyers = $user->buyers;
        } else if ($this->isBroker() && $user->broker) {
            $buyers = $user->broker->buyers;
        }

        loadObjects($buyers, [
            'individual',
            'company',
            'officers',
            'primary_address',
            'mailing_address',
            'email_contact',
            'phone1_contact',
            'phone2_contact',
            'documents',
            'lawyers',
        ]);

        $data = [
            "buyers" => $buyers,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    /**
     * Return the buyer
     *
     * @param $buyerIds
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1($buyerIds)
    {
        $buyerIds = array_unique(array_filter(array_map('intval', explode(',', $buyerIds))));

        $buyers = $this->_getBuyer($buyerIds);

        if (!$buyers
            || sizeof($buyers) != sizeof($buyerIds)
        ) {
            ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
        }

        foreach ($buyers as $buyer) {
            loadObjects($buyer, [
                'individual',
                'company',
                'officers',
                'primary_address',
                'mailing_address',
                'email_contact',
                'phone1_contact',
                'phone2_contact',
                'documents',
                'lawyers',
            ]);
        }

        $data = [
            "buyers" => $buyers,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method2($buyerIds)
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can access buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker) || $this->isDeveloper())) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        if (is_string($buyerIds)) {
            $buyerIds = array_unique(array_filter(array_map('intval', explode(',', $buyerIds))));
        } else if (is_int($buyerIds)) {
            $buyerIds = [$buyerIds];
        } else if (is_array($buyerIds) && isSequential($buyerIds)) {
            // continue
        } else {
            \Log::warn('_getBuyer: invalid input => ' . var_dump($buyerIds));
            return;
        }

        if (!$buyerIds) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                'buyer_id' => ['Buyer id is required.'],
            ]);
        }

        $buyers = [];

        if ($this->isCustomer()) {
            $_buyers = $user->buyers()
                ->whereIn(BuyerFields::ID, $buyerIds)
                ->get();
            foreach ($_buyers as $buyer) {
                $buyers[] = $buyer;
                $foundBuyerIds[] = $buyer->id;
            }
        } else if ($this->isBroker() && $user->broker) {
            $_buyers = $user->broker
                ->buyers()
                ->whereIn(BuyerFields::ID, $buyerIds)
                ->get();

            $foundBuyerIds = [];
            foreach ($_buyers as $buyer) {
                $buyers[] = $buyer;
                $foundBuyerIds[] = $buyer->id;
            }

            // If user is team lead,
            // and buyer has submitted an EOI for his/her project,
            // then can access
            $projectBrokers = $user->broker
                ->projects()
                ->whereIn(ProjectBrokerFields::PROJECT_BROKER_TYPE, [
                    PROJECT_BROKER_TYPE_TEAMLEAD,
                    PROJECT_BROKER_TYPE_ASSISTANT_TEAMLEAD
                ])
                ->get();
            $propertyIds = [];
            foreach ($projectBrokers as $projectBroker) {
                $propertyIds[] = $projectBroker->project->property_id;
            }

            if ($propertyIds) {
                foreach ($buyerIds as $buyerId) {
                    if (in_array($buyerId, $foundBuyerIds))
                        continue;

                    $eoi = Eoi::whereIn(EoiFields::PROPERTY_ID, $propertyIds)
                        ->whereHas('buyers', function ($query) use ($buyerId) {
                            $query->whereId($buyerId);
                        })
                        ->first();
                    if ($eoi) {
                        $buyers[] = Buyer::whereId($buyerId)->first();
                    }
                }
            }
        } else if ($this->isDeveloper()) {

            $developer = $user->getDeveloper();
            if (!$developer) {
                \App::abort(403, "User do not have any associated developer");
            }

            $saleBuyers = SaleBuyer::whereIn(SaleBuyerFields::BUYER_ID, $buyerIds)
                ->whereHas('sale', function ($query) use ($developer) {
                    $query->whereDeveloperId($developer->id)
                        ->whereNotIn(SaleFields::STATUS, [
                            SALE_STATUS_DRAFT,
                            SALE_STATUS_PENDING,
                            SALE_STATUS_SUBMITTED,
                        ]);
                })
                ->get();

            foreach ($saleBuyers as $saleBuyer) {
                $buyers[] = $saleBuyer->buyer;
            }
        }

        return $buyers;
    }

    /**
     * Check the buyer access for current user
     *
     * @param $buyerId
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1($buyerId)
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can access buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        $data = [
            "has_access" => !!$this->_getBuyer($buyerId),
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    /**
     * list the buyers who has access for current user
     *
     * @param $buyerId
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1($buyerId)
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can access buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }
        $buyer = $user->buyers()->where(['user_buyers.is_owner'=>1, 'user_buyers.buyer_id'=>$buyerId])->first();

        if(!$buyer){
            ssAbort('Unauthorised. Not the owner of account', ERROR_CODE_UNAUTHORIZED);
        }

        $users = $buyer->users()
            ->get();
        $brokers = $buyer->brokers()
            ->get();
        $brokers_user_data = [];
        foreach ($brokers as $key=>$broker){
            $user=User::whereId($broker->user_id)->first()->toArray();
            $brokers_user_data[$broker->user_id]=$user;
        }
        //print_r($brokers);exit;
        //$brokers->load('users');
        $data = [
            "users" => $users,
            "brokers" => $brokers,
            "brokers_user_data" => $brokers_user_data,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    /**
     * Search from whole buyers database filtered by the given search criteria
     *
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1()
    {
        $user = getAPIUser();

        // Only Broker can search buyer records
        if (!($this->isBroker() && $user->broker)) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        $params = \Request::all();

        $include = array_get($params, 'include');
        $include = array_filter(array_map('trim', explode(',', $include)));

        $validator = \Validator::make($params, [
            'filter_field' => 'required|max:100|in:email_contact_value,phone1_contact_value,individual_national_id,individual_passport_no,company_reg_no',
            'filter_value' => 'required|max:100',
            EoiFields::PROPERTY_LAUNCH_ID => 'int|exists:' . PropertyLaunchFields::TABLE_NAME . ',' . PropertyLaunchFields::ID,
        ]);
        if ($validator->fails()) {
            ssAbort("Invalid Params", ERROR_CODE_PARAM_INVALID, 0, $validator->errors());
        }

        $filterField = array_get($params, 'filter_field');
        $filterValue = array_get($params, 'filter_value');
        $propertyLaunchId = array_get($params, EoiFields::PROPERTY_LAUNCH_ID);

        $buyer = $this->buyerService->searchBuyer($filterField, $filterValue);

        $data = [];

        if ($buyer) {
            // Filter fields that needs to be sent in response
            $data["buyer"] = $this->buyerService->getBuyerShortDetails($buyer);

            // Include EOI if exists
            if ($propertyLaunchId) {
                $eois = Eoi::wherePropertyLaunchId($propertyLaunchId)
                    ->whereHas('buyers', function($query) use($buyer) {
                        $query->whereId($buyer->id);
                    })
                    ->get();

                if ($eois && !$eois->isEmpty()) {
                    loadObjects($eois, [
                        'property_launch',
                        'property',
                        'brokers',
                        'buyers',
                        'instruments',
                        'documents',
                    ], true);

                    $data["eois"] = $eois;

                    $unitIds = [];
                    $brokersMap = [];
                    $agencyIds = [];

                    foreach($eois as $eoi) {
                        if (in_array('units', $include)) {
                            if ($eoi->units) {
                                foreach(json_decode($eoi->units) as $_unitIds) {
                                    $unitIds = array_merge($unitIds, $_unitIds);
                                }
                            }
                        }

                        if (in_array('brokers', $include)) {
                            foreach ($eoi->brokers as $eoiBroker) {
                                if (!array_has($brokersMap, $eoiBroker->broker_user_id)) {
                                    $eoiBroker->broker->loadField('user');
                                    $brokersMap[$eoiBroker->broker_user_id] = $eoiBroker->broker;
                                }
                                $agencyIds[] = $eoiBroker->broker->agency_id;
                            }
                        }
                    }
                    if ($unitIds) {
                        $units = Unit::whereIn(UnitFields::ID, array_unique($unitIds))
                            ->get();
                        $unitData = [];
                        foreach($units as $unit) {
                            $unitData[] = [
                                UnitFields::ID => $unit->id,
                                UnitFields::NAMED_ID => $unit->named_id,
                                UnitFields::NAME => $unit->name,
                                UnitFields::TYPE => $unit->type,
                                UnitFields::FLOOR_NO => $unit->floor_no,
                                UnitFields::UNIT_NO => $unit->unit_no,
                            ];
                        }
                        $data["units"] = $unitData;
                    }

                    if ($brokersMap) {
                        $data['brokers'] = array_values($brokersMap);
                    }

                    if ($agencyIds) {
                        $agencies = Agency::whereIn(AgencyFields::ID, array_unique($agencyIds))
                            ->get();
                        $agencyData = [];
                        foreach($agencies as $agency) {
                            $agencyData[] = [
                                AgencyFields::ID => $agency->id,
                                AgencyFields::NAME => $agency->name,
                            ];
                        }
                        $data["agencies"] = $agencyData;
                    }
                }
            }

            // Broker access status
            $data['buyer_access_status'] = !!$this->_getBuyer($buyer->id);
        }

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    /**
     * Save buyer information
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1()
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can create new buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        $params = \Request::all();

        $type = array_get($params, BuyerFields::TYPE);

        // Following fields are not allowed
        $params['email_contact_' . ContactFields::VERIFIED] = false;
        $params['phone1_contact_' . ContactFields::VERIFIED] = false;
        $params['phone2_contact_' . ContactFields::VERIFIED] = false;

        if ($this->isCustomer() && $user->buyers->isEmpty()) {
            if ($type != BUYER_TYPE_INDIVIDUAL) {
                ssAbort('Invalid Params', ERROR_CODE_PARAM_INVALID, null, [
                    'type' => ['The first buyer must be an individual.'],
                ]);
            } else {
                $email = array_get($params, 'email_contact_' . ContactFields::VALUE);
                if ($email != $user->email) {
                    ssAbort('Invalid Params', ERROR_CODE_PARAM_INVALID, null, [
                        'email_contact_' . ContactFields::VALUE => ['The first buyer\'s email should be same as user\'s email.'],
                    ]);
                }
                $params['email_contact_' . ContactFields::VERIFIED] = true;
            }
        }

        $this->buyerService->validateBuyer($params);

        $buyer = $this->buyerService->saveBuyer($params);

        // Attach buyer to the user
        $isOwner = false;
        if ($this->isCustomer()) {
            if ($user->buyers->isEmpty() || $type == BUYER_TYPE_COMPANY) {
                $isOwner = true;
            }
            $user->buyers()->attach($buyer, [UserBuyerFields::IS_OWNER => $isOwner]);
        } else if ($this->isBroker()) {
            $user->broker->buyers()->attach($buyer);
        }

        // Load the model graph for later use, also need to send this in call response
        $buyer->load([
            'individual',
            'company',
            'primaryAddress',
            'mailingAddress',
            'emailContact',
            'phone1Contact',
            'phone2Contact',
            'documents',
        ]);

        // If the current user doesn't own this buyer account,
        // then create verification email token and send to buyer for authorisation approval
        if (!$isOwner) {

            $emailTokenType = null;
            if ($this->isCustomer()) {
                $emailTokenType = VALIDATION_TOKEN_TYPE_USER_BUYER_AUTHORISATION;
            } else if ($this->isBroker()) {
                $emailTokenType = VALIDATION_TOKEN_TYPE_BROKER_BUYER_AUTHORISATION;
            }

            $sendTo = null;
            if ($buyer->emailContact && $buyer->emailContact->value) {
                $sendTo = $buyer->emailContact->value;
            } else if ($buyer->phone1Contact && $buyer->phone1Contact->value) {
                $sendTo = $buyer->phone1Contact->value;
            }

            if ($sendTo) {
                $dataToSave = ['user_id' => $user->id, 'buyer_id' => $buyer->id];
                $dataToSend = ['user' => $user, 'buyer' => $buyer];

                $this->validationTokenService->create(
                    $emailTokenType,
                    $sendTo,
                    $dataToSave,
                    $dataToSend
                );
            }
        }

        loadObjects($buyer, [
            'individual',
            'company',
            'officers',
            'primary_address',
            'mailing_address',
            'email_contact',
            'phone1_contact',
            'phone2_contact',
            'documents',
            'lawyers',
        ]);

        $data = [
            "buyer" => $buyer,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    /**
     * Updates buyer.
     *
     * @param $buyerId
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1($buyerId)
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can create new buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        // Get Buyer
        $buyer = null;
        if ($this->isCustomer()) {
            $buyer = $user->buyers()->whereId($buyerId)->first();
        } else if ($this->isBroker()) {
            $buyer = $user->broker->buyers()->whereId($buyerId)->first();

            if (!$buyer) {
                $eois = Eoi::whereNotIn(EoiFields::STATUS, [EOI_STATUS_DRAFT])
                    ->whereHas('buyers', function($query) use($buyerId) {
                        $query->whereId($buyerId);
                    })
                    ->get();

                $eoiPropertyIds = [];
                foreach ($eois as $eoi) {
                    $eoiPropertyIds[] = $eoi->property_id;
                }

                $projectBroker = $user->broker
                    ->projects()
                    ->whereIn(ProjectBrokerFields::PROJECT_BROKER_TYPE, [
                        PROJECT_BROKER_TYPE_TEAMLEAD,
                        PROJECT_BROKER_TYPE_ASSISTANT_TEAMLEAD
                    ])
                    ->whereHas('project', function($query) use($eoiPropertyIds) {
                        $query->whereIn(AgencyProjectFields::PROPERTY_ID, $eoiPropertyIds);
                    })
                    ->first();

                if ($projectBroker) {
                    $buyer = Buyer::find($buyerId);
                }
            }
        }
        if (!$buyer) {
            ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
        }

        $params = \Request::all();
        $type = array_get($params, BuyerFields::TYPE);

        // Following fields are not allowed
        $params['email_contact_' . ContactFields::VERIFIED] = false;
        $params['phone1_contact_' . ContactFields::VERIFIED] = false;
        $params['phone2_contact_' . ContactFields::VERIFIED] = false;

        if ($type != $buyer->type) {
            ssAbort('Invalid Params', ERROR_CODE_PARAM_INVALID, null, [
                'type' => ['Buyer type cannot be changed.'],
            ]);
        }

        $this->buyerService->validateBuyer($params, $buyer);

        $buyer = $this->buyerService->saveBuyer($params, $buyer);

        // Load the model graph for later use, also need to send this in call response
        // Though the model graph should be already loaded during update :-)
        $buyer->load([
            'individual',
            'company',
            'primaryAddress',
            'mailingAddress',
            'emailContact',
            'phone1Contact',
            'phone2Contact',
            'documents',
        ]);

        // Notify all stack holders about the change, by email.
        $this->buyerService->sendBuyerUpdateEmail($user, $buyer);

        loadObjects($buyer, [
            'individual',
            'company',
            'officers',
            'primary_address',
            'mailing_address',
            'email_contact',
            'phone1_contact',
            'phone2_contact',
            'documents',
            'lawyers',
        ]);

        $data = [
            "buyer" => $buyer,
        ];

        return SSResponse::getSuccessResponse($data);
    }

    /**
     * Deletes a buyer
     *
     * @param $buyerId
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1($buyerId)
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can delete/detach buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        $params = \Request::all();
        $otherUserId = array_get($params, 'user_id');
        $brokerUserId = array_get($params, 'broker_user_id');

        if ($this->isCustomer()) {
            $buyer = $user->buyers()->whereId($buyerId)->first();

            if (!$buyer) {
                ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
            }

            if ($buyer->pivot->is_owner) {
                if ($otherUserId || $brokerUserId) {
                    if ($otherUserId) {
                        $buyerUser = $buyer->users()->whereId($otherUserId)->first();
                        if (!$buyerUser) {
                            ssAbort('Not Found', ERROR_CODE_NOT_FOUND);
                        }
                        $buyer->users()->detach($otherUserId);
                    } else if ($brokerUserId) {
                        $buyerBroker = $buyer->brokers()->whereUserId($brokerUserId)->first();
                        if (!$buyerBroker) {
                            ssAbort('Not Found', ERROR_CODE_NOT_FOUND);
                        }
                        $buyer->brokers()->detach($brokerUserId);
                    }
                } else {
                    // If no other buyer specified, and owner want to withdraw his/her own access
                    // then just delete the buyer
                    $this->buyerService->deleteBuyer($user, $buyer);
                }
            } else {
                // If customer is not owner of this buyer,
                // then can only withdraw his/her access
                if ($otherUserId || $brokerUserId) {
                    ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
                }

                $user->buyers()->detach($buyer);
            }
        } else if ($this->isBroker() && $user->broker) {

            // Broker can only withdraw his/her access
            if ($otherUserId || $brokerUserId) {
                ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
            }

            $buyer = $user->broker->buyers()->whereId($buyerId)->first();
            if (!$buyer) {
                ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
            }

            $user->broker->buyers()->detach($buyer);
        }

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS);
    }

    // Company Authorised Offices --------------------------------------------------------------------------------------

    public function method1($companyBuyerId)
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can access buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort2(ERROR_CODE_UNAUTHORIZED, 401004);
        }

        if (!$companyBuyerId) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                'company_buyer_id' => ['Company buyer id is required.'],
            ]);
        }

        // Get company buyer form user's buyers
        $companyBuyer = null;
        if ($this->isCustomer()) {
            $companyBuyer = $user->buyers()->whereId($companyBuyerId)->first();
        } else if ($this->isBroker() && $user->broker) {
            $companyBuyer = $user->broker->buyers()->whereId($companyBuyerId)->first();
        }

        if (!$companyBuyer) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 304004);
        }
        if ($companyBuyer->type != BUYER_TYPE_COMPANY) {
            ssAbort2(ERROR_CODE_CONFLICT, 304003, [
                'company_buyer_id' => 'Incorrect buyer type. Expecting company.',
            ]);
        }

        $data = [
            "officers" => $companyBuyer->officers,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method1($companyBuyerId, $individualBuyerId=null)
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can access buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort2(ERROR_CODE_UNAUTHORIZED, 401004);
        }

        // Params
        $params = \Request::all();

        $individualBuyerId = array_get($params, 'individual_buyer_id', $individualBuyerId);
        $designation = array_get($params, 'designation');

        if (!$companyBuyerId) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                'company_buyer_id' => ['Company buyer id is required.'],
            ]);
        }
        if (!$individualBuyerId) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                'individual_buyer_id' => ['Individual buyer id is required.'],
            ]);
        }

        // Get company buyer form user's buyers
        $companyBuyer = null;
        $individualBuyer = null;
        if ($this->isCustomer()) {
            $companyBuyer = $user->buyers()->whereId($companyBuyerId)->first();
            $individualBuyer = $user->buyers()->whereId($individualBuyerId)->first();
        } else if ($this->isBroker() && $user->broker) {
            $companyBuyer = $user->broker->buyers()->whereId($companyBuyerId)->first();
            $individualBuyer = $user->broker->buyers()->whereId($individualBuyerId)->first();
        }

        if (!$companyBuyer) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 304004);
        }
        if ($companyBuyer->type != BUYER_TYPE_COMPANY) {
            ssAbort2(ERROR_CODE_CONFLICT, 304003, [
                'company_buyer_id' => 'Incorrect buyer type. Expecting company.',
            ]);
        }

        if (!$individualBuyer) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 304004);
        }
        if ($individualBuyer->type != BUYER_TYPE_INDIVIDUAL) {
            ssAbort2(ERROR_CODE_CONFLICT, 304003, [
                'individual_buyer_id' => 'Incorrect buyer type. Expecting individual.',
            ]);
        }

        $companyOfficer = $this->buyerService->saveCompanyOffice($companyBuyer, $individualBuyer, $designation);

        $data = [
            "officer" => $companyOfficer,
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method1($companyBuyerId, $individualBuyerId)
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can access buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort2(ERROR_CODE_UNAUTHORIZED, 401004);
        }

        if (!$companyBuyerId) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                'company_buyer_id' => ['Company buyer id is required.'],
            ]);
        }
        if (!$individualBuyerId) {
            ssAbort2(ERROR_CODE_PARAM_INVALID, 412001, [
                'individual_buyer_id' => ['Individual buyer id is required.'],
            ]);
        }

        // Get company buyer form user's buyers
        $companyBuyer = null;
        if ($this->isCustomer()) {
            $companyBuyer = $user->buyers()->whereId($companyBuyerId)->first();
        } else if ($this->isBroker() && $user->broker) {
            $companyBuyer = $user->broker->buyers()->whereId($companyBuyerId)->first();
        }

        if (!$companyBuyer) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 304004);
        }

        $companyOfficer = $companyBuyer->officers()->whereIndividualBuyerId($individualBuyerId)->first();
        if (!$companyOfficer) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 304005);
        }

        $companyOfficer->delete();

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS);
    }

    // Buyer Access Requests -------------------------------------------------------------------------------------------

    public function method1()
    {
        $user = getAPIUser();

        // Only for Buyer User
        if (!$this->isCustomer()) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        // Get byters and load entity graph for later use,
        // also need to send in response
        $buyers = $user->buyers;
        if ($buyers) {
            $buyers->load([
                'individual',
                'company',
                //'primaryAddress',
                //'mailingAddress',
                'emailContact',
                //'phone1Contact',
                //'phone2Contact',
                //'documents',
            ]);
        }

        // Get buyer emails for fetching tokens
        $buyerEmails = [];
        foreach ($buyers as $buyer) {
            if ($buyer->emailContact) {
                $buyerEmails[] = $buyer->emailContact->value;
            }
        }

        // Get tokens
        $validationTokens = $this->validationTokenService->getTokens(
            $buyerEmails,
            [
                VALIDATION_TOKEN_TYPE_USER_BUYER_AUTHORISATION,
                VALIDATION_TOKEN_TYPE_BROKER_BUYER_AUTHORISATION
            ]
        );

        // Get sender users and receiver buyers entities
        $senderUsers = [];
        $receiverBuyers = [];

        if ($validationTokens) {
            foreach ($validationTokens as $validationToken) {

                // User
                $data = $validationToken->getDataJson();
                if ($data) {
                    $senderUserId = array_get($data, 'user_id');
                    if ($senderUserId && !array_has($senderUsers, $senderUserId)) {
                        $senderUser = $this->userService->find($senderUserId);
                        $senderUsers[$senderUserId] = [
                            UserFields::ID => $senderUser->id,
                            UserFields::FIRST_NAME => $senderUser->first_name,
                            UserFields::LAST_NAME => $senderUser->last_name,
                            UserFields::DISPLAY_NAME => $senderUser->display_name,
                        ];
                    }
                }

                // Buyer
                if (!array_has($receiverBuyers, $validationToken->sent_to)) {
                    foreach ($buyers as $buyer) {
                        if ($validationToken->sent_to == $buyer->emailContact->value) {
                            $receiverBuyers[$validationToken->sent_to] = $buyer;
                            break;
                        }
                    }
                }
            }
        }

        $data = [
            'validation_tokens' => $validationTokens,
            'buyers' => array_values($receiverBuyers),
            'users' => array_values($senderUsers),
        ];

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method1()
    {
        $user = getAPIUser();

        // Only Customer or Broker can create new buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        $params = \Request::all();

        $validator = \Validator::make($params, [
            'buyer_id' => 'required|integer|exists:buyers,id',
            'method' => 'in:email,phone',
        ]);
        if ($validator->fails()) {
            ssAbort("Invalid Params", ERROR_CODE_PARAM_INVALID, 0, $validator->errors());
        }

        $buyerId = array_get($params, 'buyer_id');
        $method = array_get($params, 'method');

        $data = [];

        $buyers = $this->_getBuyer($buyerId);
        if ($buyers) {
            $buyer = $buyers[0];
            loadObjects($buyer, [
                'individual',
                'company',
                'officers',
                'primary_address',
                'mailing_address',
                'email_contact',
                'phone1_contact',
                'phone2_contact',
                'documents',
                'lawyers',
            ]);
            $data['buyer'] = $buyer;
            $data['buyer_access_status'] = true;

            return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
        }

        $buyer = $this->buyerService->find($buyerId);
        if (!$buyer) {
            ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
        }

        // Get contact
        $contact = null;
        if ($method == 'email') {
            $contact = $buyer->emailContact;
        } else if ($method == 'phone') {
            $contact = $buyer->phone1Contact;
        } else {
            $contact = $buyer->phone1Contact ? $buyer->phone1Contact : $buyer->emailContact;
        }
        if (!$contact) {
            ssAbort2(ERROR_CODE_NOT_FOUND, 304007);
        }

        // Get type
        $type = null;
        if ($this->isCustomer()) {
            $type = VALIDATION_TOKEN_TYPE_USER_BUYER_AUTHORISATION;
        } else if ($this->isBroker() && $user->broker) {
            $type = VALIDATION_TOKEN_TYPE_BROKER_BUYER_AUTHORISATION;
        }

        // Create validation toke and send by email/sms
        $this->validationTokenService->create(
            $type,
            $contact->value,
            [
                'user_id' => $user->id,
                'buyer_id' => $buyer->id,
            ],
            [
                'user' => $user,
                'buyer' => $buyer,
            ]
        );

        $data['buyer'] = $this->buyerService->getBuyerShortDetails($buyer);
        $data['buyer_access_status'] = false;

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS, $data);
    }

    public function method1($buyerId)
    {
        $user = getAPIUser();

        // Only Customer or Broker can create new buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        // Params validation
        $params = \Request::all();

        $validator = \Validator::make($params, [
            'field' => 'required|string|in:email_contact,phone1_contact,phone2_contact',
        ]);
        if ($validator->fails()) {
            ssAbort("Invalid Params", ERROR_CODE_PARAM_INVALID, 0, $validator->errors());
        }

        $field = array_get($params, 'field');

        $buyer = $this->buyerService->find($buyerId);
        if (!$buyer) {
            ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
        }

        $contact = $buyer->{strCamelcase($field)};

        if ($contact->verified) {
            ssAbort2(ERROR_CODE_CONFLICT, 304006);
        }

        $userName = $user->display_name ? $user->display_name : implode(' ', [$user->first_name, $user->last_name]);
        if ($this->isBroker()) {
            $userName .= ' of '. $user->broker->agency->name;
        }
        $message = $userName . ' requests to access your profile registered with test. The one-time password to proceed is: ';

        $this->validationTokenService->create(
            VALIDATION_TOKEN_TYPE_BUYER_CONTACT_VERIFICATION,
            $contact->value,
            [
                'user_id' => $user->id,
                'buyer_id' => $buyer->id,
                'field' => $field,
            ],
            [
                'user' => $user,
                'buyer' => $buyer,
                'contact' => $contact,
                'message' => $message
            ]
        );

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS);
    }

    // Buyer Documents -------------------------------------------------------------------------------------------------

    /**
     * Get buyer documents.
     *
     * @param $buyerId
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1($buyerId)
    {
        $user = getAPIUser();

        // Only Customer or Broker can call this
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        // Get Buyer
        $buyer = null;
        if ($this->isCustomer()) {
            $buyer = $user->buyers()->whereId($buyerId)->first();
        } else if ($this->isBroker()) {
            $buyer = $user->broker->buyers()->whereId($buyerId)->first();
        }
        if (!$buyer) {
            ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
        }

        $buyerDocuments = $this->buyerService->getBuyerDocuments($buyer);

        $data = [
            "buyer_documents" => $buyerDocuments,
        ];

        return SSResponse::getSuccessResponse($data);
    }

    /**
     * Save buyer document.
     *
     * @param $buyerId
     * @return mixed
     * @throws \test\Exceptions\SSException
     */
    public function method1($buyerId, $buyerDocumentId=null)
    {
        $user = getAPIUser();

        // Only Customer or Broker can call this
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        // Get Buyer
        $buyer = null;
        if ($this->isCustomer()) {
            $buyer = $user->buyers()->whereId($buyerId)->first();
        } else if ($this->isBroker()) {
            $buyer = $user->broker->buyers()->whereId($buyerId)->first();

            if (!$buyer) {
                $eois = Eoi::whereNotIn(EoiFields::STATUS, [EOI_STATUS_DRAFT])
                    ->whereHas('buyers', function($query) use($buyerId) {
                        $query->whereId($buyerId);
                    })
                    ->get();

                $eoiPropertyIds = [];
                foreach ($eois as $eoi) {
                    $eoiPropertyIds[] = $eoi->property_id;
                }

                $projectBroker = $user->broker
                    ->projects()
                    ->whereIn(ProjectBrokerFields::PROJECT_BROKER_TYPE, [
                        PROJECT_BROKER_TYPE_TEAMLEAD,
                        PROJECT_BROKER_TYPE_ASSISTANT_TEAMLEAD
                    ])
                    ->whereHas('project', function($query) use($eoiPropertyIds) {
                        $query->whereIn(AgencyProjectFields::PROPERTY_ID, $eoiPropertyIds);
                    })
                    ->first();

                if ($projectBroker) {
                    $buyer = Buyer::find($buyerId);
                }
            }
        }
        if (!$buyer) {
            ssAbort("Not authorised to access this buyer.", ERROR_CODE_NOT_FOUND);
        }

        // Buyer Document
        $buyerDocument = null;
        if ($buyerDocumentId) {
            $buyerDocument = $this->buyerService->getBuyerDocument($buyer, $buyerDocumentId);
            if (!$buyerDocument) {
                ssAbort("Document Not Found", ERROR_CODE_NOT_FOUND);
            }
        }

        $params = \Request::all();

        $this->buyerService->validateBuyerDocument($params);

        $buyerDocument = $this->buyerService->saveBuyerDocument($buyer, $params, $buyerDocument);

        $data = [
            "buyer_document" => $buyerDocument,
        ];

        return SSResponse::getSuccessResponse($data);
    }

    /**
     * Delete a buyer document
     *
     * @param $buyerId
     * @param $buyerDocumentId
     * @return mixed
     * @throws \Exception
     * @throws \test\Exceptions\SSException
     */
    public function method1($buyerId, $buyerDocumentId)
    {
        $user = getAPIUser();

        // Only Customer or Broker can call this
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        // Get Buyer
        $buyer = null;
        if ($this->isCustomer()) {
            $buyer = $user->buyers()->whereId($buyerId)->first();
        } else if ($this->isBroker()) {
            $buyer = $user->broker->buyers()->whereId($buyerId)->first();
        }
        if (!$buyer) {
            ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
        }

        $buyerDocument = $this->buyerService->getBuyerDocument($buyer, $buyerDocumentId);
        if (!$buyerDocument) {
            ssAbort("Not Found", ERROR_CODE_NOT_FOUND);
        }

        $this->buyerService->deleteBuyerDocument($buyerDocument);

        return SSResponse::getJsonResponse(true, ERROR_CODE_SUCCESS);
    }

    /**
     * Export buyer list from broker management.
     * @return mixed
     * @throws \Exception
     * @throws \test\Exceptions\SSException
     */
    public function method1()
    {
        $user = getAPIUser();

        // Only Buyer User or Broker can access buyer records
        if (!($this->isCustomer() || ($this->isBroker() && $user->broker))) {
            ssAbort('Unauthorised', ERROR_CODE_UNAUTHORIZED);
        }

        $buyers = null;

        if ($this->isCustomer()) {
            $buyers = $user->buyers;
        } else if ($this->isBroker() && $user->broker) {
            $buyers = $user->broker->buyers;
        }

        loadObjects($buyers, [
            'individual',
            'company',
            'officers',
            'primary_address',
            'mailing_address',
            'email_contact',
            'phone1_contact',
            'phone2_contact',
            'documents',
            'lawyers',
            'users'
        ]);

        $data = [
            "buyers" => $buyers,
        ];

        $buyersArray[]=['No', 'Date', 'Name', 'Email', 'Contact Number', 'NIRC/PASSPORT / COMPANY REG. NO', 'STATUS'];
        foreach($buyers as $key=>$buyer){
            $verified =($buyer->emailContact->verified)?'Verified':'Not Verified';
            $email =($buyer->emailContact->value)?$buyer->emailContact->value:'';
            $created_at =!empty($buyer->emailContact->created_at)?$buyer->emailContact->created_at:'';
            $display_name  =!empty($buyer->individual)?$buyer->individual->first_name.' '.$buyer->individual->last_name:$buyer->company->name;
            $phone1Contactvalue =!empty($buyer->phone1Contact->value)?$buyer->phone1Contact->value:'';


            if(!empty($buyer->individual)){
                $passport_no  =!empty($buyer->individual->passport_no)?$buyer->individual->passport_no:$buyer->individual->national_id;
            }
            if(!empty($buyer->company)){
                $passport_no  =!empty($buyer->company->reg_no)?$buyer->company->reg_no:'';
            }
            //$passport_no  =!empty($buyer->individual)?($buyer->individual->identity_type=='singaporean' || $buyer->individual->identity_type=='singaporean_r')?$buyer->individual->national_id:$buyer->individual->passport_no:$buyer->company->reg_no;
            $buyersArray[]=[$key+1, $created_at, $display_name, $email, $phone1Contactvalue, $passport_no, $verified];
        }
        Excel::create('BUYERS LIST', function ($exportBuyers) use ($buyersArray) {
            // Set the spreadsheet title, creator, and description
            $exportBuyers->setTitle('BUYERS LIST');
            $exportBuyers->setCreator('test')->setCompany('Show Suite');
            $exportBuyers->setDescription('Buyers List Export');
            //Property
            $exportBuyers->sheet('Buyers', function ($sheet) use ($buyersArray) {
                $sheet->fromArray($buyersArray, null, 'A1', true, false);
            });

        })->download('xlsx');

    }

}