<?php

namespace App\Traits;

use App\Models\InstagramProfiles;
use App\Models\Todos;
use App\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client as Guzzle;
use \GuzzleHttp\Exception\GuzzleException;

trait InstagramProfileHelper
{
    /**
     * @param User $user
     * @param string $url
     * @return array
     */
    static function addProfile(User $user, string $url): array
    {
        // Trying to retrieve profile information
        $client = new Guzzle();
        try {
            $options = [
                'headers' => [
                    'User-Agent' => 'IGUD/' . \Config::get('app.version'),
                    'Accept'     => '*/*',
                ],
            ];
            if(env('SOCKS5_HOST', false) and env('SOCKS5_PORT', false)) {
                $options['proxy'] = 'socks5://' . env('SOCKS5_HOST') . ':' . env('SOCKS5_PORT');
            }
            $response = $client->request('GET', $url, $options);
        } catch (ClientException $e) {
            if($e->getCode() === 429) {
                $todo = new Todos();
                $todo->data = json_encode((object)[
                    'user_id' => $user->id,
                    'url' => $url,
                ]);
                $todo->type_id = \Config::get('const.todo_types.add_profile');
                $todo->save();

                $res = [
                    'status' => false,
                    'messages' => ["I'm pretty busy right now. I'll try to add this Instagram profile later and I'll notify you!"],
                    'code' => $e->getCode(),
                ];
                return $res;
            }

            $res = [
                'status'   => false,
                'messages' => [$e->getMessage()],
                'code'     => $e->getCode(),
            ];
            return $res;
        } catch (RequestException $e) {
            $res = [
                'status'   => false,
                'messages' => [$e->getMessage()],
                'code'     => $e->getCode(),
            ];
            return $res;
        } catch (GuzzleException $e) {
            $res = [
                'status'   => false,
                'messages' => [$e->getMessage()],
                'code'     => $e->getCode(),
            ];
            return $res;
        }

        if ($response and $response->getStatusCode() == 200) {
            // Does magic parsing (sigh)
            preg_match('/<script type="text\/javascript">window\._sharedData = (.*?)<\/script>/',
                (string)$response->getBody(), $response);
            $profile_data = json_decode(substr($response[1], 0, -1));

            $graphql = $profile_data->entry_data->ProfilePage[0]->graphql ?? null;

            if (!$graphql) {
                $res = [
                    'status'   => false,
                    'messages' => ["The URL is not an Instagram profile page"],
                    'code'     => 400,
                ];
                return $res;
            }

            // Check for the user in the database, updates it if present or creates it if not present
            $db_profile = InstagramProfiles::where('instagram_id', '=', $graphql->user->id)
                ->withTrashed()->first();
            if ($db_profile) {
                $db_profile->update([
                    'name'         => $graphql->user->username,
                    'full_name'    => $graphql->user->full_name,
                    'instagram_id' => $graphql->user->id,
                    'profile_pic'  => $graphql->user->profile_pic_url,
                    'is_private'   => $graphql->user->is_private,
                    'deleted_at'   => null,
                ]);
                if ($db_profile->followers->count() == 1) {
                    $db_profile->update(['last_check' => Carbon::now()]);
                }
            } else {
                $db_profile = new InstagramProfiles([
                    'name'         => $graphql->user->username,
                    'full_name'    => $graphql->user->full_name,
                    'instagram_id' => $graphql->user->id,
                    'profile_pic'  => $graphql->user->profile_pic_url,
                    'is_private'   => $graphql->user->is_private,
                    'last_check'   => Carbon::now(),
                ]);
                $db_profile->save();
            }

            // Adds the profile to the followed ones
            if ($user->followedProfiles->contains($db_profile->id)) {
                $res = [
                    'status'   => true,
                    'messages' => ['You already added this profile'],
                    'code'     => 200,
                ];
                return $res;
            } else {
                $user->followedProfiles()->attach($db_profile);
                $res = [
                    'status'   => true,
                    'messages' => ['Instagram profile added'],
                    'profile'  => $db_profile->toArray(),
                    'code'     => 201,
                ];
                return $res;
            }

        } else {
            $res = [
                'status'   => false,
                'messages' => ['Error retrieving profile'],
                'code'     => $response->getStatusCode(),
            ];
            return $res;
        }
    }
}
