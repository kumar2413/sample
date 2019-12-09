"use strict";

class ApplicationController {

  constructor($scope, $window, SSAppService, SSHttpService, SSUtilService, SSDateService, SSAlertService, $timeout, SSConfigService, NgTableParams) {
    const self = this;

    self.$scope = $scope;
    self.$window = $window;
    self.SSAppService = SSAppService;
    self.SSHttpService = SSHttpService;
    self.SSUtilService = SSUtilService;
    self.SSDateService = SSDateService;
    self.SSAlertService = SSAlertService;
    self.$timeout = $timeout;
    self.SSConfigService = SSConfigService;
    self.data = TEST.data;
    self.NgTableParams = NgTableParams;

    self.SALE_STATUS_DRAFT = TEST.SALE_STATUS_DRAFT;
    self.SALE_STATUS_PENDING = TEST.SALE_STATUS_PENDING;
    self.SALE_STATUS_SUBMITTED = TEST.SALE_STATUS_SUBMITTED;
    self.SALE_STATUS_VERIFIED = TEST.SALE_STATUS_VERIFIED;
    self.SALE_STATUS_APPROVED = TEST.SALE_STATUS_APPROVED;
    self.SALE_STATUS_REJECTED = TEST.SALE_STATUS_REJECTED;
    self.SALE_STATUS_OTP = TEST.SALE_STATUS_OTP;
    self.init();
  }

  init() {
    const self = this;

    self.properties = [];
    self.sales = [];
    self.property_id = "";
    self.sale_status = "";
    self.projects = [];
    self.limit = 10;
    self.offset = 0;
    self.sales_total = 0;
    self.order_by = '{"created_at": "desc"}';
      self.pageChanged = function(newPage) {
          self.retrieveApplications(newPage);
      };
    self.prepareDeveloperProperties();
    self.prepareSaleStatuses();

    self.countries = self.SSConfigService.getCountries();
  }

  method1() {
    const self = this;
      let properties_available=[];

    let url = 'sec/developer/properties';
    loading(true);
    self.SSHttpService.getAPIRequest(url).then(function (response) {
      loading(false);
      if (response instanceof Error) {          
          self.SSAlertService.parseAndDisplayError(response);
          return;
      }

      self.properties = response.data.properties;
        $.each(self.properties, function(index, property) {
            if (property.status === self.PROPERTY_STATUS_APPROVED || property.status === self.PROPERTY_STATUS_LISTED || property.status === self.PROPERTY_STATUS_ARCHIVED) {
               properties_available.push(property);
            }
        });

      self.properties=properties_available;
      self.$timeout(function() { $("select.dashboard-select#property-select").niceSelect('update'); });
      self.retrieveApplications();
    });
  }

    method2(index, sale) {
    const self = this;

    self.selected_sale = sale;

    let close = true;
    if ($("#control_" + index).hasClass("show")) {
      close = false;
    }

    $(".controls").removeClass("show");

    if (close) {
      $("#control_" + index).toggleClass("show");
    }
  }

    method3() {
    const self = this;

    self.hideModals();
    let url = 'sec/developer/sales/' + self.selected_sale.id + '/status';
    let params = {
      'status': self.SALE_STATUS_APPROVED
    };

    loading(true);
    self.SSHttpService.putAPIRequest(url, params).then(function (response) {
      loading(false);
      if (response instanceof Error) {
        self.SSAlertService.parseAndDisplayError(response);
        return;
      }

      //Update sale status
      self.selected_sale.status = self.SALE_STATUS_APPROVED;
      self.SSAlertService.success('Success!', 'Sale is approved successfully.');

      self.showOTPModal = true;
      $('#developerSaleApplicationOTPModal').modal('show');
    });
  }

    method4() {
    const self = this;

    self.hideModals();

    self.showOTPModal = true;
    $('#developerSaleApplicationOTPModal').modal('show');
  }

    method5() {
    const self = this;
    //url to get all status
    let url = 'sec/developer/sales/' + self.selected_sale.id + '/status';
    let params = {
      'status': self.SALE_STATUS_REJECTED
    };

    loading(true);
    self.SSHttpService.putAPIRequest(url, params).then(function (response) {
      loading(false);
      self.hideModals();
      if (response instanceof Error) {        
        self.SSAlertService.parseAndDisplayError(response);
        return;
      }

      //Update sale status
      self.selected_sale.status = self.SALE_STATUS_REJECTED;
      self.SSAlertService.success('Success!', 'Sale is rejected successfully.');
    });
  }

    method6() {
    const self = this;

    let url = 'sec/developer/sales/' + self.selected_sale.id + '/generate-otp';

    loading(true);
    self.SSHttpService.getAPIRequest(url).then(function (response) {
      if (response instanceof Error) {
        loading(false);      
        self.SSAlertService.parseAndDisplayError(response);
        return;
      }

      self.hideModals();
      self.selected_sale.status = self.SALE_STATUS_OTP;
      //get sale document file details
      let sale_document = response.data.sale_document;
      self.selected_sale.documents.push(sale_document);

      self.SSAlertService.success('Success!', 'OTP is generated successfully.');

      //use presigned url to update assets
      url = 'sec/assets/presigned-url';
      let params = {
        'model': self.ASSET_MODEL_SALE_DOCUMENT,
        'id': sale_document.id
      };
      self.SSHttpService.getAPIRequest(url, params).then(function (response) {
        loading(false);
        if (response instanceof Error) {
          self.SSAlertService.parseAndDisplayError(response);
          return;
        }

        //Open OTP in new tab
        window.open(response.data.presigned_url, '_blank');
      });
    });    
  }

    method7() {
    const self = this;

    let sale_document = self.selected_sale.documents.filter(x => x.type === self.PROPERTY_DOCUMENT_TYPE_SYSTEM_GENERATED && x.document_kind === self.PROPERTY_DOCUMENT_KIND_OTP);

    if (sale_document.length > 0) {
      let url = 'sec/assets/presigned-url';
      let params = {
        'model': self.ASSET_MODEL_SALE_DOCUMENT,
        'id': sale_document[0].id
      };

      loading(true);
      self.SSHttpService.getAPIRequest(url, params).then(function (response) {
        loading(false);
        if (response instanceof Error) {
          self.SSAlertService.parseAndDisplayError(response);
          return;
        }

        //Open OTP in new tab
        window.open(response.data.presigned_url, '_blank');
      });
    }
    
  }

    method8() {
    const self = this;

    self.sale_statuses = [
      {
        'id': self.SALE_STATUS_VERIFIED,
        'name': 'Pending'
      },
      {
        'id': self.SALE_STATUS_REJECTED,
        'name': 'Rejected'
      },
      {
        'id': self.SALE_STATUS_APPROVED,
        'name': 'Accepted'
      }
    ];

    self.$timeout(function() { $("select.dashboard-select#status-select").niceSelect('update'); });
  }

  onReview(sale) {
    const self = this;

    self.$window.location.href = '/developer/sales/applications/review?sale_id=' + sale.id;

  }

  onAccept(sale) {
    const self = this;

    self.hideModals();

    self.showAccept = true;
    $('#developerSaleApplicationAcceptModal').modal('show');
  }

  onReject(sale) {
    const self = this;

    self.hideModals();

    self.showReject = true;
    $('#developerSaleApplicationRejectModal').modal('show');
  }

  hideModals() {
    const self = this;

    self.showReview = false;
    self.showAccept = false;
    self.showReject = false;
    self.showAgent = false;
    self.showBuyer = false;
    self.showOTPModal = false;

    //hide all models
    if ($('#developerSaleApplicationReviewModal').is(':visible')) $('#developerSaleApplicationReviewModal').modal('hide');
    if ($('#developerSaleApplicationAcceptModal').is(':visible')) $('#developerSaleApplicationAcceptModal').modal('hide');
    if ($('#developerSaleApplicationRejectModal').is(':visible')) $('#developerSaleApplicationRejectModal').modal('hide');
    if ($('#developerSaleApplicationAgentModal').is(':visible')) $('#developerSaleApplicationAgentModal').modal('hide');
    if ($('#developerSaleApplicationBuyerModal').is(':visible')) $('#developerSaleApplicationBuyerModal').modal('hide');
    if ($('#developerSaleApplicationOTPModal').is(':visible')) $('#developerSaleApplicationOTPModal').modal('hide');
  }

  previousPage() {
    const self = this;

    self.offset = self.offset - parseInt(self.limit);
    self.retrieveApplications();
  }

  nextPage() {
    const self = this;

    self.offset = self.offset + parseInt(self.limit);
    self.retrieveApplications();
  }

  updateFilter() {
    const self = this;

    self.offset = 0;
    self.retrieveApplications();
  }

  onViewSummary(sale) {
    const self = this;

    self.$window.location.href = '/developer/sales/applications/summary?sale_id=' + sale.id;
  }

    method11(page) {
    const self = this;
    self.SSAlertService.message = null

    let status = self.sale_status;

    if (self.sale_status == "" || self.sale_status == null) {
      status = self.SALE_STATUS_VERIFIED + ',' + self.SALE_STATUS_REJECTED + ',' + self.SALE_STATUS_OTP + ',' + self.SALE_STATUS_APPROVED;
    } else if (self.sale_status == self.SALE_STATUS_APPROVED) {
      status = self.SALE_STATUS_OTP + ',' + self.SALE_STATUS_APPROVED;
    }

    //check if page exists
    if(page) {
        self.offset = (page * self.limit) - self.limit;
    }

    let url = 'sec/developer/sales';

    //load all params
    let params = {
      'property_id': self.property_id,
      'status': status,
      'limit': self.limit,
      'offset': self.offset,
      'include': 'eois, properties, units, brokers, agencies, sale_buyers, question_answers, documents',
      'send_total_count': true,
      'order': self.order_by
    };

    loading(true);
    self.SSHttpService.getAPIRequest(url, params).then(function (response) {      
      loading(false);
      if (response instanceof Error) {
          self.SSAlertService.parseAndDisplayError(response);
          return;
      }

      self.sales = response.data.sales;
      self.sales_total = response.data.total_count;

      if (self.sales) {
        jQuery.each(self.sales, function (i, sale) {
          sale.aml = sale.aml ? JSON.parse(sale.aml) : null;
        });
      }

      let brokers = response.data.brokers;

      //Process broker and agency
      $.each(brokers, function (index, broker) {
        broker["agency"] = response.data.agencies.find(x => x.id === broker.agency_id);
      });

      //Process sales
      $.each(self.sales, function( index, sale ) {
        let main_broker_user_id = sale.brokers.find(x => x.broker_role === self.BROKER_ROLE_MAIN_BROKER).broker_user_id;

        sale['main_broker'] = brokers.find(x => x.user_id === main_broker_user_id);

        sale['buyers'] = [];

        $.each(sale.sale_buyers, function(index, buyer) {
          let processed_buyer = JSON.parse(buyer.data);
          sale['buyers'].push(processed_buyer);
        });

        sale["eoi"] = response.data.eois.find(x => x.id === sale.eoi_id);
        sale["unit"] = response.data.units.find(x => x.id === sale.unit_id);

        sale['property_name'] = self.properties.find(x => x.id === sale.eoi.property_id).name;
      });

        });
  }

    method13(broker) {
    const self = this;
    self.hideModals();

    self.showAgent = true;

    self.selected_broker = broker;
    $('#developerSaleApplicationAgentModal').modal('show');
  }

    method14(buyers) {
    const self = this;
    self.hideModals();

    self.showBuyer = true;
    self.selected_buyers = buyers;
    $('#developerSaleApplicationBuyerModal').modal('show');
  }

}

ApplicationController.$inject = ['$scope', '$window', 'SSAppService', 'SSHttpService', 'SSUtilService', 'SSDateService', 'SSAlertService', '$timeout', 'SSConfigService', 'NgTableParams'];
app.controller('ApplicationController', ApplicationController);