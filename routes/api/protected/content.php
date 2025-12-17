<?php


use App\Http\Controllers\BlogCommentController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ContentMasterController;
use App\Http\Controllers\PagesController;
use App\Http\Controllers\PopUpController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

//pages
Route::get('pages-list', [PagesController::class, 'index']);
Route::post('pages-store', [PagesController::class, 'store']);
Route::post('pages-update/{id}', [PagesController::class, 'update']);
Route::delete('pages-destroy/{id}', [PagesController::class, 'destroy']);
Route::get('pages-show/{id}', [PagesController::class, 'show']);

//blog
Route::get('blog-list', [BlogController::class, 'index']);
Route::get('blog-dashbord', [BlogController::class, 'deshbordData']);
Route::post('blog-store', [BlogController::class, 'store']);
Route::post('blog-update/{id}', [BlogController::class, 'update']);
Route::delete('blog-destroy/{id}', [BlogController::class, 'destroy']);
Route::get('top-viewed-blogs', [BlogController::class, 'topViewedBlogs']);
Route::get('dashboard/chart-data', [BlogController::class, 'chartStats']);
Route::get('most-used-category', [BlogController::class, 'getMostUsedCategory']);

//BlogComment
Route::get('blog-comment-list', [BlogCommentController::class, 'index']);
Route::post('blog-comment-store/{blog_id}', [BlogCommentController::class, 'store']);
Route::post('blog-comment-update/{id}', [BlogCommentController::class, 'update']);
Route::delete('blog-comment-destroy/{id}', [BlogCommentController::class, 'destroy']);
Route::post('blog-comments/{id}/like', [BlogCommentController::class, 'toggleLike']);
Route::get('most-liked-comment-blog', [BlogCommentController::class, 'mostLikedCommentWithBlog']);

//Shop
Route::get('shop-list', [ShopController::class, 'index']);
Route::post('shop-store', [ShopController::class, 'store']);
Route::post('shop-update/{id}', [ShopController::class, 'update']);
Route::get('shop-show/{id}', [ShopController::class, 'show']);
Route::delete('shop-destroy/{id}', [ShopController::class, 'destroy']);

//popup
Route::post('wc-mdl-store', [PopUpController::class, 'store']);
Route::post('wc-mdl-update/{id}', [PopUpController::class, 'update']);
Route::get('wc-mdl-show/{id}', [PopUpController::class, 'show']);
Route::delete('wc-mdl-destroy/{id}', [PopUpController::class, 'destroy']);

// Content Master
Route::get('content-master', [ContentMasterController::class, 'index']);
Route::post('content-master', [ContentMasterController::class, 'store']);
Route::get('content-master/{id}', [ContentMasterController::class, 'show']);
Route::post('content-master/{id}', [ContentMasterController::class, 'update']);
Route::delete('content-master/{id}', [ContentMasterController::class, 'destroy']);
Route::get('content-master/user/{userId}', [ContentMasterController::class, 'getByUser']);
