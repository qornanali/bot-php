<?php

namespace App\Line;

use Illuminate\Support\Facades\Cache;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use Carbon\Carbon;
use App\Imgur\Images;
use LINE\LINEBot\MessageBuilder;

class BotRepository
{
    /**
     * @var LINEBot
     */
    protected $bot;
    /**
     * @var HTTPClient
     */
    protected $client;

    protected $image;

    public function __construct(Images $image)
    {
        $this->image = $image;
        $this->client = new CurlHTTPClient(env('LINE_BOT_ACCESS_TOKEN'));
        $this->bot = new LINEBot($this->client, ['channelSecret' => env('LINE_BOT_SECRET')]);
    }
    
    public function replyMsg($replyToken,$msgResponse, $isMedia = false){
        if($isMedia){
            // image
            $msgResponse = new \LINE\LINEBot\MessageBuilder\ImageMessageBuilder($msgResponse,$msgResponse);
            $response = $this->bot->replyMessage($replyToken, $msgResponse);
            return $response->isSucceeded();
        }

        $response = $this->bot->replyText($replyToken,  $msgResponse);
        return $response->isSucceeded();
    }
    
    public function getBot(){
        return $this->bot;
    }

    public function getMsg($ev = [],$onlyMsg = false){
        $msgUser = !empty($ev['message']['text']) ? $ev['message']['text'] : '';
        if($onlyMsg){
            return $msgUser;
        }

        return $this->collectMsg($msgUser,$ev);
    }

    private function collectMsg($msgUser = '',$ev){
        $userID = $this->userID($ev);

        if(Cache::get($userID.'create_meme')){
            return $this->startMeme($ev);
        }
        $this->forgetCache($userID);

        if(strtolower($msgUser) == 'help' || $msgUser == ''){
            return $this->help();
        }

        if(strtolower($msgUser) == 'maen meme'){
            return $this->meme($ev);
        }

        return 'Ga jelas lu!!!
        ketik *help* buat bantuan!';
    }

    public function forgetCache($userID = '',$keys = []){
        // forget
        $defaultKey = [
            'meme_header',
            'meme_footer',
            'create_meme',
            'meme_ready',
            'create_ready'
        ];
        if(empty($keys)){
            $keys = $defaultKey;
        }

        foreach($keys as $key){
            Cache::forget($userID.$key);
        }
        
        return true;
    }

    private function help(){
        $msg = 'Buatanye Alan.
        Buat lo yang pengen bikin meme, silahkan ketik: 
        maen Meme';

        return $msg;
    }

    private function meme($ev = []){
        $userID = $this->userID($ev);
        $expiresAt = Carbon::now()->addMinutes(2);
        Cache::add($userID.'create_meme', true, $expiresAt);

        $msg = 'Tulis kata untuk menaruh gambar di HEADER';
        return $msg;
    }

    private function startMeme($ev = []) {
        $userID = $this->userID($ev);
        $expiresAt = Carbon::now()->addMinutes(2);
        $keyHeader = $userID.'meme_header';
        $keyFooter = $userID.'meme_footer';
        $keyReady = $userID.'meme_ready';
        $getHeader = Cache::get($keyHeader);
        $getFooter = Cache::get($keyFooter);

        if(!$getHeader){
            Cache::add($keyHeader, $this->getMsg($ev,true), $expiresAt);
            return 'Tulis kata untuk menaruh gambar di FOOTER';
        }

        if($getHeader && !$getFooter){
            Cache::add($keyFooter, $this->getMsg($ev,true), $expiresAt /*minutes*/);
            return 'Sekarang coba upload gambar lo';
        }

        if($getHeader && $getFooter){
            // upload image
            $image = $this->getMedia($ev);
            $imgURL = $this->image->upload($image);
            $toMemeURL = $this->image->meme($imgURL,$getHeader,$getFooter);

            Cache::add($keyReady, true, $expiresAt);

            return $toMemeURL; //$toMemeURL;
        }
        return true;
    }

    public function userID($ev = []){
        $userType = !empty($ev['source']) ? $ev['source']['type'] : false;
        if($userType == 'user'){
            return $ev['source']['userId'];
        }

        return false;
    }

    private function getMedia($ev = []){
        $msgID = (int)$ev['message']['id'];
        $response = $this->bot->getMessageContent($msgID);

        if ($response->isSucceeded()) {
            return base64_encode($response->getRawBody()); //$response->getRawBody() || json_encode([$response,$msgID]);
        } else {
            error_log($response->getHTTPStatus() . ' ' . $response->getRawBody());
        }

        return false;

    }
}