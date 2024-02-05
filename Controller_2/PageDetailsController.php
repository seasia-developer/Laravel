<?php

namespace App\Http\Controllers;

use App\Constant\Constants;
use App\Http\Helper\Helper;
use App\Http\Resources\PageDetailsResource;
use App\Http\Resources\PartnerResource;
use App\Image;
use App\PageDetails;
use App\BecomeAPartnerParagraph;
use App\Partner;
use App\pageDetailsMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PageDetailsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function storeHomePage(Request $request)
    {
        $request->validate([
            'page' => 'required|string',
            // 'page_id' => 'required|integer',
            'type' => 'required'
        ]);
        $model = PageDetails::where('page_id', $request->page_id)
            ->where('type', $request->type)
            ->with('meta')
            ->first();
        try {
            DB::beginTransaction();
            $user = Auth::user();
            $details = [];
            $msg = 'Page saved successfully.';
            $request['user_id'] = $user->id; //current logged in user id
            if ($request->type == Constants::ANNOUNCEMENT_SECTION) {
                $request->validate([
                    'content' => 'required|string',
                    'link' => 'required|string',
                ]);
                $details['link'] = $request->filled('link') ? $request->link : '';
                $details['content'] = $request->filled('content') ? $request->content : '';
                $request['details'] = json_encode($details);
                $model->update($request->all());
                $msg = 'Page updated successfully.';
            }
            if ($request->type == Constants::BANNER_SECTION) {
                $request->validate([
                    'banner_title' => 'nullable|string',
                    'banner_subtitle' => 'nullable|string',
                    'banner_content' => 'nullable|string'
                ]);
                $details['banner_image'] = '';
                if (!empty($model)) {
                    $data = json_decode($model->details);
                    $details['banner_image'] = $data->banner_image;
                }
                $details['banner_title'] = $request->filled('banner_title') ? $request->banner_title : '';
                $details['banner_subtitle'] = $request->filled('banner_subtitle') ? $request->banner_subtitle : '';
                $details['banner_content'] = $request->filled('banner_content') ? $request->banner_content : '';
                $uploadedBannerImage =  null;


                $details['banner_image'] = $request->banner_image;
                if ($request->hasFile('banner_image')) {
                    $uploadedBannerImage = Helper::saveImage($request->banner_image, 'page-details-images', $request->type);
                    if (!empty($uploadedBannerImage)) {
                        $details['banner_image'] = $uploadedBannerImage;
                    }
                }

                $request['details'] = json_encode($details);
                $request['section'] = $request->filled('section_main') ? $request->section_main : '';

                if (empty($model)) {
                    $model = PageDetails::create($request->all());
                } else {
                    $model->update($request->all());
                }
                if (!empty($uploadedBannerImage)) {
                    $image = new Image();
                    $image->name = $request->banner_image->getClientOriginalName();
                    $image->file_name =  $uploadedBannerImage;
                    $image->extension = $request->banner_image->getClientOriginalExtension();
                    if ($user) {
                        $image->user()->associate($user);
                    }
                    $model->images()->save($image);
                }
            }
            if ($request->type == Constants::HOW_IT_WORKS) {
                $request->validate([
                    'heading_one' => 'nullable|string',
                    'banner_content_one' => 'nullable|string',
                    'heading_two' => 'nullable|string',
                    'banner_content_two' => 'nullable|string',
                    'heading_three' => 'nullable|string',
                    'banner_content_three' => 'nullable|string',
                    'button_text' =>  'nullable|string',
                    'button_link' =>  'nullable|string',
                ]);
                $details['heading_one'] = $request->filled('heading_one') ? $request->heading_one : '';
                $details['banner_content_one'] = $request->filled('banner_content_one') ? $request->banner_content_one : '';
                $details['heading_two'] = $request->filled('heading_two') ? $request->heading_two : '';
                $details['banner_content_two'] = $request->filled('banner_content_two') ? $request->banner_content_two : '';
                $details['heading_three'] = $request->filled('heading_three') ? $request->heading_three : '';
                $details['banner_content_three'] = $request->filled('banner_content_three') ? $request->banner_content_three : '';
                $details['button_text'] = $request->filled('button_text') ? $request->button_text : '';
                $details['button_link'] = $request->filled('button_link') ? $request->button_link : '';
                $request['details'] = json_encode($details);
                $request['section'] = $request->filled('section_main') ? $request->section_main : '';

                if (empty($model)) {
                    $model = PageDetails::create($request->all());
                } else {
                    $model->update($request->all());
                }
            }
            if ($request->type == Constants::THREE_SECTION_PANEL) {
                $request->validate([
                    'section_title_one' => 'nullable|string',
                    'sub_title_one' => 'nullable|string',
                    'link_one' => 'nullable|string',
                    'section_title_two' => 'nullable|string',
                    'sub_title_two' => 'nullable|string',
                    'link_two' => 'nullable|string',
                    'section_title_three' => 'nullable|string',
                    'sub_title_three' => 'nullable|string',
                    'link_three' => 'nullable|string',
                ]);
                $details['section_title_one'] = $request->filled('section_title_one') ? $request->section_title_one : '';
                $details['sub_title_one'] = $request->filled('sub_title_one') ? $request->sub_title_one : '';
                $details['link_one'] = $request->filled('link_one') ? $request->link_one : '';
                $uploadedBannerImageOne = null;
                $uploadedBannerImageTwo = null;
                $uploadedBannerImageThree = null;
                $details['banner_image_one'] = '';
                $details['banner_image_two'] = '';
                $details['banner_image_three'] = '';
                if (!empty($model)) {
                    $data = json_decode($model->details);
                    $details['banner_image_one'] = $data->banner_image_one;
                    $details['banner_image_two'] = $data->banner_image_two;
                    $details['banner_image_three'] = $data->banner_image_three;
                }


                $details['banner_image_one'] = $request->banner_image_one;

                if ($request->hasFile('banner_image_one')) {
                    $uploadedBannerImageOne = Helper::saveImage($request->banner_image_one, 'page-details-images', $request->type);
                    if (!empty($uploadedBannerImageOne)) {
                        $details['banner_image_one'] = $uploadedBannerImageOne;
                    }
                }

                $details['section_title_two'] = $request->filled('section_title_two') ? $request->section_title_two : '';
                $details['sub_title_two'] = $request->filled('sub_title_two') ? $request->sub_title_two : '';
                $details['link_two'] = $request->filled('link_two') ? $request->link_two : '';


                $details['banner_image_two'] = $request->banner_image_two;

                if ($request->hasFile('banner_image_two')) {
                    $uploadedImageTwo = Helper::saveImage($request->banner_image_two, 'page-details-images', $request->type);
                    if (!empty($uploadedImageTwo)) {
                        $details['banner_image_two'] = $uploadedImageTwo;
                    }
                }

                $details['section_title_three'] = $request->filled('section_title_three') ? $request->section_title_three : '';
                $details['sub_title_three'] = $request->filled('sub_title_three') ? $request->sub_title_three : '';
                $details['link_three'] = $request->filled('link_three') ? $request->link_three : '';


                $details['banner_image_three'] = $request->banner_image_three;

                if ($request->hasFile('banner_image_three')) {
                    $uploadedImageThree = Helper::saveImage($request->banner_image_three, 'page-details-images', $request->type);
                    if (!empty($uploadedImageThree)) {
                        $details['banner_image_three'] = $uploadedImageThree;
                    }
                }

                $request['details'] = json_encode($details);
                $request['section'] = $request->filled('section_main') ? $request->section_main : '';

                if (empty($model)) {
                    $model = PageDetails::create($request->all());
                } else {
                    $model->update($request->all());
                }
                if ($model) {
                    if (!empty($uploadedBannerImageOne)) {
                        $imageOne = new Image();
                        $imageOne->name = $request->banner_image_one->getClientOriginalName();
                        $imageOne->file_name =  $uploadedBannerImageOne;
                        $imageOne->extension = $request->banner_image_one->getClientOriginalExtension();
                        $imageOne->user()->associate($user);
                        $model->images()->save($imageOne);
                    }
                    if (!empty($uploadedBannerImageTwo)) {
                        $imageTwo = new Image();
                        $imageTwo->name = $request->banner_image_two->getClientOriginalName();
                        $imageTwo->file_name =  $uploadedBannerImageTwo;
                        $imageTwo->extension = $request->banner_image_two->getClientOriginalExtension();
                        $imageTwo->user()->associate($user);
                        $model->images()->save($imageTwo);
                    }
                    if (!empty($uploadedBannerImageThree)) {
                        $imageThree = new Image();
                        $imageThree->name = $request->banner_image_three->getClientOriginalName();
                        $imageThree->file_name =  $uploadedBannerImageThree;
                        $imageThree->extension = $request->banner_image_three->getClientOriginalExtension();
                        $imageThree->user()->associate($user);
                        $model->images()->save($imageThree);
                    }
                }
            }
            if ($request->type == Constants::STATS) {
                $request->validate([
                    'number_one' => 'nullable|string',
                    'content_one' => 'nullable|string',
                    'number_two' => 'nullable|string',
                    'content_two' => 'nullable|string',
                    'number_three' => 'nullable|string',
                    'content_three' => 'nullable|string',
                    'number_four' => 'nullable|string',
                    'content_four' => 'nullable|string',
                ]);
                $details['number_one'] = $request->filled('number_one') ? $request->number_one : '';
                $details['content_one'] = $request->filled('content_one') ? $request->content_one : '';
                $details['number_two'] = $request->filled('number_two') ? $request->number_two : '';
                $details['content_two'] = $request->filled('content_two') ? $request->content_two : '';
                $details['number_three'] = $request->filled('number_three') ? $request->number_three : '';
                $details['content_three'] = $request->filled('content_three') ? $request->content_three : '';
                $details['number_four'] = $request->filled('number_four') ? $request->number_four : '';
                $details['content_four'] = $request->filled('content_four') ? $request->content_four : '';
                $request['details'] = json_encode($details);
                $request['section'] = $request->filled('section_main') ? $request->section_main : '';

                if (empty($model)) {
                    $model = PageDetails::create($request->all());
                } else {
                    $model->update($request->all());
                }
            }
            if ($request->type == Constants::MIDDLE_CONTENT) {
                $request->validate([
                    'title' => 'nullable|string',
                    'sub_title' => 'nullable|string',
                    'content' => 'nullable|string'
                ]);
                $details['title'] = $request->filled('title') ? $request->title : '';
                $details['sub_title'] = $request->filled('sub_title') ? $request->sub_title : '';
                $details['content'] = $request->filled('content') ? $request->content : '';
                $uploadedImageOne = null;
                $uploadedImageTwo = null;
                $uploadedImageThree = null;
                $uploadedImageFour = null;
                $details['image_one'] = '';
                $details['image_two'] = '';
                $details['image_three'] = '';
                $details['image_four'] = '';
                if (!empty($model)) {
                    $data = json_decode($model->details);
                    $details['image_one'] = $data->image_one;
                    $details['image_two'] = $data->image_two;
                    $details['image_three'] = $data->image_three;
                    $details['image_four'] = $data->image_four;
                }


                $details['image_one'] = $request->image_one;
                if ($request->hasFile('image_one')) {
                    $uploadedImageOne = Helper::saveImage($request->image_one, 'page-details-images', $request->type);
                    if (!empty($uploadedImageOne)) {
                        $details['image_one'] = $uploadedImageOne;
                    }
                }


                $details['image_two'] = $request->image_two;

                if ($request->hasFile('image_two')) {
                    $uploadedImageTwo = Helper::saveImage($request->image_two, 'page-details-images', $request->type);
                    if (!empty($uploadedImageTwo)) {
                        $details['image_two'] = $uploadedImageTwo;
                    }
                }


                $details['image_three'] = $request->image_three;
                if ($request->hasFile('image_three')) {
                    $uploadedImageThree = Helper::saveImage($request->image_three, 'page-details-images', $request->type);
                    if (!empty($uploadedImageThree)) {
                        $details['image_three'] = $uploadedImageThree;
                    }
                }


                $details['image_four'] = $request->image_four;
                if ($request->hasFile('image_four')) {
                    $uploadedImageFour = Helper::saveImage($request->image_four, 'page-details-images', $request->type);
                    if (!empty($uploadedImageFour)) {
                        $details['image_four'] = $uploadedImageFour;
                    }
                }

                $request['details'] = json_encode($details);
                $request['section'] = $request->filled('section_main') ? $request->section_main : '';

                if (empty($model)) {
                    $model = PageDetails::create($request->all());
                } else {
                    $model->update($request->all());
                }
                if ($model) {
                    if (!empty($uploadedImageOne)) {
                        $imageOne = new Image();
                        $imageOne->name = $request->image_one->getClientOriginalName();
                        $imageOne->file_name =  $uploadedImageOne;
                        $imageOne->extension = $request->image_one->getClientOriginalExtension();
                        $imageOne->user()->associate($user);
                        $model->images()->save($imageOne);
                    }
                    if (!empty($uploadedImageTwo)) {
                        $imageTwo = new Image();
                        $imageTwo->name = $request->image_two->getClientOriginalName();
                        $imageTwo->file_name =  $uploadedImageTwo;
                        $imageTwo->extension = $request->image_two->getClientOriginalExtension();
                        $imageTwo->user()->associate($user);
                        $model->images()->save($imageTwo);
                    }
                    if (!empty($uploadedImageThree)) {
                        $imageThree = new Image();
                        $imageThree->name = $request->image_three->getClientOriginalName();
                        $imageThree->file_name =  $uploadedImageThree;
                        $imageThree->extension = $request->image_three->getClientOriginalExtension();
                        $imageThree->user()->associate($user);
                        $model->images()->save($imageThree);
                    }
                    if (!empty($uploadedImageFour)) {
                        $imageFour = new Image();
                        $imageFour->name = $request->image_four->getClientOriginalName();
                        $imageFour->file_name =  $uploadedImageFour;
                        $imageFour->extension = $request->image_four->getClientOriginalExtension();
                        $imageFour->user()->associate($user);
                        $model->images()->save($imageFour);
                    }
                }
            }
            if ($request->type == Constants::FEATURED_COVERED) {
                $request->validate([
                    'featured_title' => 'nullable|string',
                    'featured_subtitle' => 'nullable|string',
                    'link' => 'nullable|string'
                ]);
                $details['featured_title'] = $request->filled('featured_title') ? $request->featured_title : '';
                $details['featured_subtitle'] = $request->filled('featured_subtitle') ? $request->featured_subtitle : '';
                $details['link'] = $request->filled('link') ? $request->link : '';
                $fc_image = null;
                $details['image'] = '';
                if (!empty($model)) {
                    $data = json_decode($model->details);
                    $details['image'] = $data->image;
                }


                $details['image'] = $request->image;
                if ($request->hasFile('image')) {
                    $fc_image = Helper::saveImage($request->image, 'page-details-images', $request->type);
                    if (!empty($fc_image)) {
                        $details['image'] = $fc_image;
                    }
                }
                $request['details'] = json_encode($details);
                $request['section'] = $request->filled('section_main') ? $request->section_main : '';

                if (empty($model)) {
                    $model = PageDetails::create($request->all());
                } else {
                    $model->update($request->all());
                }
                if ($model) {
                    if (!empty($fc_image)) {
                        $image = new Image();
                        $image->name = $request->image->getClientOriginalName();
                        $image->file_name =  $fc_image;
                        $image->extension = $request->image->getClientOriginalExtension();
                        $image->user()->associate($user);
                        $model->images()->save($image);
                    }
                }
            }

            /* Update page_details_meta table*/
            $pageDetailsMeta = pageDetailsMeta::firstOrNew(array('page' => $request->page));
            $pageDetailsMeta->page = $request->page;
            $pageDetailsMeta->type = $request->type;
            $pageDetailsMeta->meta_title = $request->filled("meta_title") ? $request->meta_title : "";
            $pageDetailsMeta->meta_Description = $request->filled("meta_description") ? $request->meta_description : "";
            $pageDetailsMeta->save();

            DB::commit();
            if (!empty($model)) {
                return $this->success(
                    [
                        'data' => new PageDetailsResource($model),
                        'message' => $msg
                    ]
                );
            } else {
                return $this->failed("Something Went Wrong!");
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }

    public function storeAboutPage(Request $request)
    {
        $request->validate([
            'page' => 'required|string',
            'page_id' => 'required|integer',
            'type' => 'required'
        ]);
        $model = PageDetails::where('page_id', $request->page_id)
            ->where('type', $request->type)
            ->with('meta')
            ->first();
        try {
            DB::beginTransaction();
            $user = Auth::user();
            $details = [];
            $request['user_id'] = $user->id; //current logged in user id
            if ($request->type == Constants::ABOUT_STORY_MOSAIC) {
                $request->validate([
                    'about_title' => 'nullable|string',
                    'about_content' => 'nullable|string',
                    'about_link' => 'nullable|string',
                ]);
                $details['about_image'] = '';
                if (!empty($model)) {
                    $data = json_decode($model->details);
                    $details['about_image'] = $data->about_image;
                }
                $details['about_title'] = $request->filled('about_title') ? $request->about_title : '';
                $details['about_content'] = $request->filled('about_content') ? $request->about_content : '';
                $details['about_link'] = $request->filled('about_link') ? $request->about_link : '';
                $uploadedAboutImage =  null;

                $details['about_image'] = $request->about_image;
                if ($request->hasFile('about_image')) {
                    $uploadedAboutImage = Helper::saveImage($request->about_image, 'page-details-images', $request->type);
                    if (!empty($uploadedAboutImage)) {
                        $details['about_image'] = $uploadedAboutImage;
                    }
                }

                $request['details'] = json_encode($details);
                $request['section'] = $request->filled('section_main') ? $request->section_main : '';

                if (empty($model)) {
                    $model = PageDetails::create($request->all());
                } else {
                    $model->update($request->all());
                }
                if (!empty($uploadedAboutImage)) {
                    $image = new Image();
                    $image->name = $request->about_image->getClientOriginalName();
                    $image->file_name =  $uploadedAboutImage;
                    $image->extension = $request->about_image->getClientOriginalExtension();
                    if ($user) {
                        $image->user()->associate($user);
                    }
                    $model->images()->save($image);
                }
            }
            if ($request->type == Constants::WHO_WE_ARE) {
                $request->validate([
                    'profile_one_title' => 'nullable|string',
                    'profile_one_designation' => 'nullable|string',
                    'profile_one_content' => 'nullable|string',
                    'profile_one_content_link' => 'nullable|string',
                    // 'profile_one_image' => 'nullable|string',

                    'profile_two_title' => 'nullable|string',
                    'profile_two_designation' => 'nullable|string',
                    'profile_two_content' => 'nullable|string',
                    'profile_two_content_link' => 'nullable|string',
                    // 'profile_two_image' => 'nullable|string',

                    'profile_three_title' => 'nullable|string',
                    'profile_three_designation' => 'nullable|string',
                    'profile_three_content' => 'nullable|string',
                    'profile_three_content_link' => 'nullable|string',
                    // 'profile_three_image' => 'nullable|string',

                    'profile_four_title' => 'nullable|string',
                    'profile_four_designation' => 'nullable|string',
                    'profile_four_content' => 'nullable|string',
                    'profile_four_content_link' => 'nullable|string',
                    // 'profile_four_image' => 'nullable|string',

                    'profile_five_title' => 'nullable|string',
                    'profile_five_designation' => 'nullable|string',
                    'profile_five_content' => 'nullable|string',
                    'profile_five_content_link' => 'nullable|string',
                    // 'profile_five_image' => 'nullable|string',

                    'profile_six_title' => 'nullable|string',
                    'profile_six_designation' => 'nullable|string',
                    'profile_six_content' => 'nullable|string',
                    'profile_six_content_link' => 'nullable|string',
                    // 'profile_six_image' => 'nullable|string',
                ]);
                $details['profile_one_title'] = $request->filled('profile_one_title') ? $request->profile_one_title : '';
                $details['profile_one_designation'] = $request->filled('profile_one_designation') ? $request->profile_one_designation : '';
                $details['profile_one_content'] = $request->filled('profile_one_content') ? $request->profile_one_content : '';
                $details['profile_one_content_link'] = $request->filled('profile_one_content_link') ? $request->profile_one_content_link : '';


                $details['profile_two_title'] = $request->filled('profile_two_title') ? $request->profile_two_title : '';
                $details['profile_two_designation'] = $request->filled('profile_two_designation') ? $request->profile_two_designation : '';
                $details['profile_two_content'] = $request->filled('profile_two_content') ? $request->profile_two_content : '';
                $details['profile_two_content_link'] = $request->filled('profile_two_content_link') ? $request->profile_two_content_link : '';


                $details['profile_three_title'] = $request->filled('profile_three_title') ? $request->profile_three_title : '';
                $details['profile_three_designation'] = $request->filled('profile_three_designation') ? $request->profile_three_designation : '';
                $details['profile_three_content'] = $request->filled('profile_three_content') ? $request->profile_three_content : '';
                $details['profile_three_content_link'] = $request->filled('profile_three_content_link') ? $request->profile_three_content_link : '';


                $details['profile_four_title'] = $request->filled('profile_four_title') ? $request->profile_four_title : '';
                $details['profile_four_designation'] = $request->filled('profile_four_designation') ? $request->profile_four_designation : '';
                $details['profile_four_content'] = $request->filled('profile_four_content') ? $request->profile_four_content : '';
                $details['profile_four_content_link'] = $request->filled('profile_four_content_link') ? $request->profile_four_content_link : '';


                $details['profile_five_title'] = $request->filled('profile_five_title') ? $request->profile_five_title : '';
                $details['profile_five_designation'] = $request->filled('profile_five_designation') ? $request->profile_five_designation : '';
                $details['profile_five_content'] = $request->filled('profile_five_content') ? $request->profile_five_content : '';
                $details['profile_five_content_link'] = $request->filled('profile_five_content_link') ? $request->profile_five_content_link : '';


                $details['profile_six_title'] = $request->filled('profile_six_title') ? $request->profile_six_title : '';
                $details['profile_six_designation'] = $request->filled('profile_six_designation') ? $request->profile_six_designation : '';
                $details['profile_six_content'] = $request->filled('profile_six_content') ? $request->profile_six_content : '';
                $details['profile_six_content_link'] = $request->filled('profile_six_content_link') ? $request->profile_six_content_link : '';


                $uploadedProfileImageOne = null;
                $uploadedProfileImageTwo = null;
                $uploadedProfileImageThree = null;
                $uploadedProfileImageFour = null;
                $uploadedProfileImageFive = null;
                $uploadedProfileImageSix = null;
                $details['profile_image_one'] = '';
                $details['profile_image_two'] = '';
                $details['profile_image_three'] = '';
                $details['profile_image_four'] = '';
                $details['profile_image_five'] = '';
                $details['profile_image_six'] = '';
                if (!empty($model)) {
                    $data = json_decode($model->details);
                    $details['profile_image_one'] = $data->profile_image_one;
                    $details['profile_image_two'] = $data->profile_image_two;
                    $details['profile_image_three'] = $data->profile_image_three;
                    $details['profile_image_four'] = $data->profile_image_four;
                    $details['profile_image_five'] = $data->profile_image_five;
                    $details['profile_image_six'] = $data->profile_image_six;
                }

                $details['profile_image_one'] = $request->profile_image_one;
                if ($request->hasFile('profile_image_one')) {
                    $uploadedProfileImageOne = Helper::saveImage($request->profile_image_one, 'page-details-images', $request->page_id);
                    if (!empty($uploadedProfileImageOne)) {
                        $details['profile_image_one'] = $uploadedProfileImageOne;
                    }
                }
                $details['profile_image_two'] = $request->profile_image_two;
                if ($request->hasFile('profile_image_two')) {
                    $uploadedProfileImageTwo = Helper::saveImage($request->profile_image_two, 'page-details-images', $request->page_id);
                    if (!empty($uploadedProfileImageTwo)) {
                        $details['profile_image_two'] = $uploadedProfileImageTwo;
                    }
                }
                $details['profile_image_three'] = $request->profile_image_three;
                if ($request->hasFile('profile_image_three')) {
                    $uploadedProfileImageThree = Helper::saveImage($request->profile_image_three, 'page-details-images', $request->page_id);
                    if (!empty($uploadedProfileImageThree)) {
                        $details['profile_image_three'] = $uploadedProfileImageThree;
                    }
                }
                $details['profile_image_four'] = $request->profile_image_four;
                if ($request->hasFile('profile_image_four')) {
                    $uploadedProfileImageFour = Helper::saveImage($request->profile_image_four, 'page-details-images', $request->page_id);
                    if (!empty($uploadedProfileImageFour)) {
                        $details['profile_image_four'] = $uploadedProfileImageFour;
                    }
                }
                $details['profile_image_five'] = $request->profile_image_five;
                if ($request->hasFile('profile_image_five')) {
                    $uploadedProfileImageFive = Helper::saveImage($request->profile_image_five, 'page-details-images', $request->page_id);
                    if (!empty($uploadedProfileImageFive)) {
                        $details['profile_image_five'] = $uploadedProfileImageFive;
                    }
                }
                $details['profile_image_six'] = $request->profile_image_six;
                if ($request->hasFile('profile_image_six')) {
                    $uploadedProfileImageSix = Helper::saveImage($request->profile_image_six, 'page-details-images', $request->page_id);
                    if (!empty($uploadedProfileImageSix)) {
                        $details['profile_image_six'] = $uploadedProfileImageSix;
                    }
                }
                $request['details'] = json_encode($details);
                $request['section'] = $request->filled('section_main') ? $request->section_main : '';

                if (empty($model)) {
                    $model = PageDetails::create($request->all());
                } else {
                    $model->update($request->all());
                }
                if ($model) {
                    if (!empty($uploadedProfileImageOne)) {
                        $imageOne = new Image();
                        $imageOne->name = $request->profile_image_one->getClientOriginalName();
                        $imageOne->file_name =  $uploadedProfileImageOne;
                        $imageOne->extension = $request->profile_image_one->getClientOriginalExtension();
                        $imageOne->user()->associate($user);
                        $model->images()->save($imageOne);
                    }
                    if (!empty($uploadedProfileImageTwo)) {
                        $imageTwo = new Image();
                        $imageTwo->name = $request->profile_image_two->getClientOriginalName();
                        $imageTwo->file_name =  $uploadedProfileImageTwo;
                        $imageTwo->extension = $request->profile_image_two->getClientOriginalExtension();
                        $imageTwo->user()->associate($user);
                        $model->images()->save($imageTwo);
                    }
                    if (!empty($uploadedProfileImageThree)) {
                        $imageThree = new Image();
                        $imageThree->name = $request->profile_image_three->getClientOriginalName();
                        $imageThree->file_name =  $uploadedProfileImageThree;
                        $imageThree->extension = $request->profile_image_three->getClientOriginalExtension();
                        $imageThree->user()->associate($user);
                        $model->images()->save($imageThree);
                    }
                    if (!empty($uploadedProfileImageFour)) {
                        $imageFour = new Image();
                        $imageFour->name = $request->profile_image_four->getClientOriginalName();
                        $imageFour->file_name =  $uploadedProfileImageFour;
                        $imageFour->extension = $request->profile_image_four->getClientOriginalExtension();
                        $imageFour->user()->associate($user);
                        $model->images()->save($imageFour);
                    }
                    if (!empty($uploadedProfileImageFive)) {
                        $imageFive = new Image();
                        $imageFive->name = $request->profile_image_five->getClientOriginalName();
                        $imageFive->file_name =  $uploadedProfileImageFive;
                        $imageFive->extension = $request->profile_image_five->getClientOriginalExtension();
                        $imageFive->user()->associate($user);
                        $model->images()->save($imageFive);
                    }
                    if (!empty($uploadedProfileImageSix)) {
                        $imageSix = new Image();
                        $imageSix->name = $request->profile_image_six->getClientOriginalName();
                        $imageSix->file_name =  $uploadedProfileImageSix;
                        $imageSix->extension = $request->profile_image_six->getClientOriginalExtension();
                        $imageSix->user()->associate($user);
                        $model->images()->save($imageSix);
                    }
                }
            }
            /* Update page_details_meta table*/
            $pageDetailsMeta = pageDetailsMeta::firstOrNew(array('page' => $request->page));
            $pageDetailsMeta->page = $request->page;
            $pageDetailsMeta->type = $request->type;
            $pageDetailsMeta->meta_title = $request->filled("meta_title") ? $request->meta_title : "";
            $pageDetailsMeta->meta_Description = $request->filled("meta_description") ? $request->meta_description : "";
            $pageDetailsMeta->save();

            DB::commit();
            if (!empty($model)) {
                return $this->success(
                    [
                        'data' => new PageDetailsResource($model),
                        'message' => 'Page saved successfully.'
                    ]
                );
            } else {
                return $this->failed("Something Went Wrong!");
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }

    public function storeHowItWorksPage(Request $request)
    {
        $request->validate([
            'page' => 'required|string',
            'page_id' => 'required|integer',
            'type' => 'required',
            'first_paragraph_content' => 'nullable|string',
            'how_it_works_section_1' => 'nullable|string',
            'how_it_works_section_2' => 'nullable|string',
            'how_it_works_section_3' => 'nullable|string',
            'how_it_works_section_4' => 'nullable|string',
            'last_paragraph_title' => 'nullable|string',
            'last_paragraph_content' => 'nullable|string',
        ]);
        $model = PageDetails::where('page_id', $request->page_id)
            ->where('type', $request->type)
            ->with('meta')
            ->first();
        try {
            DB::beginTransaction();
            $user = Auth::user();
            $details = [];
            $request['user_id'] = $user->id; //current logged in user id
            $details['image_1'] = '';
            $details['image_2'] = '';
            $details['image_3'] = '';
            $details['image_4'] = '';

            $uploadedImage1 =  null;
            $uploadedImage2 =  null;
            $uploadedImage3 =  null;
            $uploadedImage4 =  null;

            $details['first_paragraph_content'] = $request->filled('first_paragraph_content') ? $request->first_paragraph_content : '';
            $details['how_it_works_section_1'] = $request->filled('how_it_works_section_1') ? $request->how_it_works_section_1 : '';
            $details['how_it_works_section_2'] = $request->filled('how_it_works_section_2') ? $request->how_it_works_section_2 : '';
            $details['how_it_works_section_3'] = $request->filled('how_it_works_section_3') ? $request->how_it_works_section_3 : '';
            $details['how_it_works_section_4'] = $request->filled('how_it_works_section_4') ? $request->how_it_works_section_4 : '';
            $details['last_paragraph_title'] = $request->filled('last_paragraph_title') ? $request->last_paragraph_title : '';
            $details['last_paragraph_content'] = $request->filled('last_paragraph_content') ? $request->last_paragraph_content : '';

            if (!empty($model)) {
                $data = json_decode($model->details);
                $details['image_1'] = $data->image_1;
                $details['image_2'] = $data->image_2;
                $details['image_3'] = $data->image_3;
                $details['image_4'] = $data->image_4;
            }

            if ($request->hasFile('image_1')) {
                $uploadedImage1 = Helper::saveImage($request->image_1, 'page-details-images', $request->type);
                if (!empty($uploadedImage1)) {
                    $details['image_1'] = $uploadedImage1;
                }
            }
            if ($request->hasFile('image_2')) {
                $uploadedImage2 = Helper::saveImage($request->image_2, 'page-details-images', $request->type);
                if (!empty($uploadedImage2)) {
                    $details['image_2'] = $uploadedImage2;
                }
            }
            if ($request->hasFile('image_3')) {
                $uploadedImage3 = Helper::saveImage($request->image_3, 'page-details-images', $request->type);
                if (!empty($uploadedImage3)) {
                    $details['image_3'] = $uploadedImage3;
                }
            }
            if ($request->hasFile('image_4')) {
                $uploadedImage4 = Helper::saveImage($request->image_4, 'page-details-images', $request->type);
                if (!empty($uploadedImage4)) {
                    $details['image_4'] = $uploadedImage4;
                }
            }

            $request['details'] = json_encode($details);
            $request['section'] = $request->filled('section_main') ? $request->section_main : '';

            if (empty($model)) {
                $model = PageDetails::create($request->all());
            } else {
                $model->update($request->all());
            }
            if (!empty($uploadedImage1)) {
                $image1 = new Image();
                $image1->name = $request->image_1->getClientOriginalName();
                $image1->file_name =  $uploadedImage1;
                $image1->extension = $request->image_1->getClientOriginalExtension();
                if ($user) {
                    $image1->user()->associate($user);
                }
                $model->images()->save($image1);
            }
            if (!empty($uploadedImage2)) {
                $image2 = new Image();
                $image2->name = $request->image_2->getClientOriginalName();
                $image2->file_name =  $uploadedImage2;
                $image2->extension = $request->image_2->getClientOriginalExtension();
                if ($user) {
                    $image2->user()->associate($user);
                }
                $model->images()->save($image2);
            }
            if (!empty($uploadedImage3)) {
                $image3 = new Image();
                $image3->name = $request->image_3->getClientOriginalName();
                $image3->file_name =  $uploadedImage3;
                $image3->extension = $request->image_3->getClientOriginalExtension();
                if ($user) {
                    $image3->user()->associate($user);
                }
                $model->images()->save($image3);
            }
            if (!empty($uploadedImage4)) {
                $image4 = new Image();
                $image4->name = $request->image_4->getClientOriginalName();
                $image4->file_name =  $uploadedImage4;
                $image4->extension = $request->image_4->getClientOriginalExtension();
                if ($user) {
                    $image4->user()->associate($user);
                }
                $model->images()->save($image4);
            }

            /* Update page_details_meta table*/
            $pageDetailsMeta = pageDetailsMeta::firstOrNew(array('page' => $request->page));
            $pageDetailsMeta->page = $request->page;
            $pageDetailsMeta->type = $request->type;
            $pageDetailsMeta->meta_title = $request->filled("meta_title") ? $request->meta_title : "";
            $pageDetailsMeta->meta_Description = $request->filled("meta_description") ? $request->meta_description : "";
            $pageDetailsMeta->save();

            DB::commit();
            if (!empty($model)) {
                return $this->success(
                    [
                        'data' => new PageDetailsResource($model),
                        'message' => 'Page saved successfully.'
                    ]
                );
            } else {
                return $this->failed("Something Went Wrong!");
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id --- type of record
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request)
    {
        try {
            $data = [];
            $data = PageDetails::where('page_id', $request->page_id)
                ->where('type', $request->type)
                ->with('user')->with('meta')
                ->first();

            return $this->success([
                'data' => $data,
                'message' => 'section content fetched successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }
    public function showHowItWorks()
    {
        try {
            $data = [];
            $model = PageDetails::where('page_id', Constants::MANAGEABLE_PAGES_ID_HOW_IT_WORKS)
                ->with('meta')
                ->first();
            if (!empty($model)) {
                $data['details'] = json_decode($model->details);
                $data['meta'] = $model->meta;
                return $this->success([
                    'data' => $data,
                    'message' => 'section content fetched successfully'
                ]);
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }
    public function showAboutUs()
    {
        try {
            $data = [];
            $model = PageDetails::where('page_id', Constants::MANAGEABLE_PAGES_ID_ABOUT)
                ->with('meta')
                ->get();
            if (!empty($model)) {
                $details = [];
                $meta = [];
                foreach ($model as $key => $value) {
                    $details = (object)array_merge((array)json_decode($value->details), (array)$details);
                    $meta = (object)array_merge((array)json_decode($value->meta), (array)$meta);
                }
                $data['details'] = $details;
                $data['meta'] = $meta;
                return $this->success([
                    'data' => $data,
                    'message' => 'section content fetched successfully'
                ]);
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  string  $page --- type of record
     * @return \Illuminate\Http\Response
     */
    public function detailsByPage($page)
    {
        try {
            $data = [];
            $data = PageDetails::where('page', $page)->get();

            return $this->success([
                'data' => $data,
                'message' => 'section content fetched successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }



    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $request->validate(['id' => 'required']);
        $model = PageDetails::where('page_id', $request->id)
            ->first();
        if (!empty($model)) {
            try {
                $model->delete();
                return $this->success('Page deleted successfully');
            } catch (\Illuminate\Database\QueryException $exception) {
                return $this->error($exception->errorInfo);
            }
        } else {
            return $this->failed('No data found to delete.');
        }
    }

    public function getParagraph(){
        try{
            $data = [];
            $data = BecomeAPartnerParagraph::where('status', Constants::STATE_ACTIVE)->firstOrFail();
            return $this->success([
                'data' => $data,
                'message' => 'Paragraph content fetched successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function paragraphUpdate(Request $request){
        $request->validate([
            'paragraph' => 'required|string'
        ]);
        try{
            $data = [];
            $user = Auth::user();
            $click = false;
            $data = BecomeAPartnerParagraph::where('status', Constants::STATE_ACTIVE)->update([
                'paragraph' => $request->paragraph,
                'created_by_id'=> $user->id
            ]);
            if ($request->hasFile('image')) {
                $uploadedImage = Helper::saveImage($request->image, 'paragraph_partner', $user->id);
                if (!empty($uploadedImage)) {
                    $image = $uploadedImage;
                }
                $data = BecomeAPartnerParagraph::where('status', Constants::STATE_ACTIVE)->update([
                'image' => $image,
                'created_by_id'=> $user->id
            ]);
            $click = true;
            }
            if($data){
                $data = BecomeAPartnerParagraph::where('status', Constants::STATE_ACTIVE)->firstOrFail();
                return $this->success([
                    'data' => $data,
                    'click'=> $click,
                    'message' => 'Paragraph content updated successfully.'
                ]);
            }else{
                return $this->failed("Something Went Wrong!");
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function getPartner(Request $request){
        try{
            $limit = $request->limit;
            $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;

            $user = Auth::guard('api')->user();

            if($request->filled('partner_page')){
               $list = Partner::orderBy('name', 'ASC'); 
            }else{
                $list = Partner::latest('id'); 
            }
            
            $count = $list->count();

            $list = $list->where('status', Constants::STATE_ACTIVE);

            // $list->with('createdby');
            $list = $list->paginate($limit);
                return $this->success([
                    'allPartners' => $count,
                    'data' => PartnerResource::collection($list),
                    'message' => 'Faqs List fetched successfully',
                ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function addPartner(Request $request){
        $request->validate([
            'name' => 'required|string',
            'url'  => 'required|string'
        ]);
        try{
            $data = [];
            $user = Auth::user();
            if ($request->hasFile('image')) {
                $uploadedImage = Helper::saveImage($request->image, 'partners', null);
                if (!empty($uploadedImage)) {
                    $imageURL = $uploadedImage;
                }
            }else{
                return $this->failed('Partner image is required.');
            }
            $data = Partner::create([
                'name' => $request->name,
                'url' => $request->url,
                'image'=> $imageURL,
                'created_by_id'=> $user->id
            ]);
            if($data){
                return $this->success([
                    'data' => $data,
                    'message' => 'Partner add successfully.'
                ]);
            }else{
                return $this->failed("Something Went Wrong!");
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function updatePartner(Request $request){
        $request->validate([
            'id' => 'required|string',
            'name' => 'required|string',
            'url'  => 'required|string'
        ]);
        try{
            $data = [];
            $user = Auth::user();
            if ($request->hasFile('image')) {
                $uploadedImage = Helper::saveImage($request->image, 'partners', null);
                if (!empty($uploadedImage)) {
                    $imageURL = $uploadedImage;
                }
                $data = Partner::where('id', $request->id)->update([
                'image' => $imageURL,
                'created_by_id'=> $user->id
            ]);
            }
            $data = Partner::where('id',$request->id)->update([
                'name' => $request->name,
                'url' => $request->url,
                'created_by_id'=> $user->id
            ]);
            if($data){
                $data = Partner::where('id', $request->id)->firstOrFail();
                return $this->success([
                    'data' => $data,
                    'message' => 'Partner update successfully.'
                ]);
            }else{
                return $this->success([
                    'data' => $data,
                    'message' => 'Partner Image update successfully.'
                ]);
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function deletePartner(Request $request){
        $request->validate([
            'id' => 'required|string'
        ]);
        try{
            $data = [];
            $user = Auth::user();
            $data = Partner::where('id',$request->id)->delete();
            if($data){
                return $this->success([
                    'data' => $data,
                    'message' => 'Partner deleted successfully.'
                ]);
            }else{
                return $this->failed("Something Went Wrong!");
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
