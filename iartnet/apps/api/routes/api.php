<?php

use App\Http\Controllers\Api\IiifManifestController;
use App\Http\Controllers\Api\InterviewsController;
use App\Http\Controllers\Api\CardsStatController;
use App\Http\Controllers\Api\MasterDataCardController;
use App\Http\Controllers\Api\NarrationsController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/cardslist', [MasterDataCardController::class, 'cardsList']);
Route::get('/card_data', [MasterDataCardController::class, 'cardData']);
Route::get('/sitemap/records', [SitemapController::class, 'records'])->name('sitemap.records');
Route::get('/cards_stat', [CardsStatController::class, 'cardsStat'])->name('cards_stat');

// Interviews (iartnet_master.interviews)
Route::get('/interviewsList', [InterviewsController::class, 'interviewsList']);
Route::get('/interviewData', [InterviewsController::class, 'interviewData']);

// Narrations (iartnet_master.narrations)
Route::get('/narrationsList', [NarrationsController::class, 'narrationsList']);
Route::get('/narrationData', [NarrationsController::class, 'narrationData']);

// IIIF Presentation 3.0 manifest (no auth)
Route::get('/iiif/manifest/{record_id}', [IiifManifestController::class, 'show'])->name('api.iiif.manifest');

// Ricerca pubblica e autocomplete (FTS + trigram + fuzzy, published-only)
Route::get('/search_public', [SearchController::class, 'searchPublic'])->name('search_public');
Route::get('/search_suggest_terms', [SearchController::class, 'searchSuggestTerms'])->name('search_suggest_terms');