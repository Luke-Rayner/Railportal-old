<?php
/**
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
use UserFrosting\Sprinkle\Core\Util\NoCache;

// All the account routes
$app->group('/retail-sense', function () {
	// GET - Dashboard Page page
	$this->get('/retail-report-summary', 'UserFrosting\Sprinkle\RetailSense\Controller\SummaryController:pageSummary');

	$this->get('/food-stores-summary', 'UserFrosting\Sprinkle\RetailSense\Controller\FoodStoreSummaryController:pageFoodStoreSummary');

	$this->get('/none-food-store-summary', 'UserFrosting\Sprinkle\RetailSense\Controller\NoneFoodStoreSummaryController:pageNoneFoodStoreSummary');

	$this->get('/detailed-report/clothing', 'UserFrosting\Sprinkle\RetailSense\Controller\DetailedClothingReportController:pageDetailedClothingReport');

	$this->get('/detailed-report/household-goods', 'UserFrosting\Sprinkle\RetailSense\Controller\DetailedHouseholdGoodsReportController:pageDetailedHouseholdGoodsReport');

	$this->get('/detailed-report/none-store', 'UserFrosting\Sprinkle\RetailSense\Controller\DetailedNoneStoreReportController:pageDetailedNoneStoreReport');

	$this->get('/detailed-report/fuel', 'UserFrosting\Sprinkle\RetailSense\Controller\DetailedFuelReportController:pageDetailedFuelReport');
});

// All the geo-sense api routes
$app->group('/retail-sense/api', function () {
	$this->get('/stats/month_weekly_average/sales/excl_fuel/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageSalesExclFuel');

	$this->get('/stats/month_weekly_average/sales_percentage_change/excl_fuel/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageSalesPercentageChangeExclFuel');

	$this->get('/stats/month_weekly_average/online_sales/excl_fuel/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineSalesExclFuel');

	$this->get('/stats/month_weekly_average/online_sales_percentage_change/excl_fuel/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineSalesPercentageChangeExclFuel');

	$this->get('/stats/month_weekly_average/online_penetration/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlinePenetration');

	$this->get('/stats/month_weekly_average/food_store_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageFoodStoreSales');

	$this->get('/stats/month_weekly_average/food_store_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageFoodStoreSalesPercentageChange');

	$this->get('/stats/month_weekly_average/online_food_store_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineFoodStoreSales');

	$this->get('/stats/month_weekly_average/online_food_store_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineFoodStoreSalesPercentageChange');

	$this->get('/stats/month_weekly_average/none_food_store_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageNoneFoodStoreSales');

	$this->get('/stats/month_weekly_average/none_food_store_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageNoneFoodStoreSalesPercentageChange');

	$this->get('/stats/month_weekly_average/online_none_food_store_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineNoneFoodStoreSales');

	$this->get('/stats/month_weekly_average/online_none_food_store_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineNoneFoodStoreSalesPercentageChange');

	$this->get('/stats/month_weekly_average/textile_clothing_footwear_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageTextileClothingFootwearSales');

	$this->get('/stats/month_weekly_average/textile_clothing_footwear_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageTextileClothingFootwearSalesPercentageChange');

	$this->get('/stats/month_weekly_average/online_textile_clothing_footwear_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineTextileClothingFootwearSales');

	$this->get('/stats/month_weekly_average/online_textile_clothing_footwear_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineTextileClothingFootwearSalesPercentageChange');

	$this->get('/stats/month_weekly_average/household_goods_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageHouseholdGoodsSales');

	$this->get('/stats/month_weekly_average/household_goods_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageHouseholdGoodsSalesPercentageChange');

	$this->get('/stats/month_weekly_average/online_household_goods_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineHouseholdGoodsSales');

	$this->get('/stats/month_weekly_average/online_household_goods_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineHouseholdGoodsSalesPercentageChange');

	$this->get('/stats/month_weekly_average/none_store_retailing_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageNoneStoreRetailingSales');

	$this->get('/stats/month_weekly_average/none_store_retailing_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageNoneStoreRetailingSalesPercentageChange');

	$this->get('/stats/month_weekly_average/online_none_store_retailing_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineNoneStoreRetailingSales');

	$this->get('/stats/month_weekly_average/online_none_store_retailing_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageOnlineNoneStoreRetailingSalesPercentageChange');

	$this->get('/stats/month_weekly_average/fuel_sales/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageFuelSales');
	
	$this->get('/stats/month_weekly_average/fuel_sales_percentage_change/{start}/{end}', 'UserFrosting\Sprinkle\RetailSense\Controller\ApiController:listMonthWeeklyAverageFuelSalesPercentageChange');
});

// All the retail-sense admin routes
$app->group('/admin/retail-sense', function () {
	$this->get('/retail/upload', 'UserFrosting\Sprinkle\RetailSense\Controller\RetailController:pageUploadRetailStats');
	$this->post('/retail/upload', 'UserFrosting\Sprinkle\RetailSense\Controller\RetailController:uploadRetailStats');
});