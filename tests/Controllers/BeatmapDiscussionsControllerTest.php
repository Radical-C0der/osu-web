<?php

namespace Tests\Controllers;

use App\Models\Beatmap;
use App\Models\BeatmapDiscussion;
use App\Models\BeatmapDiscussionPost;
use App\Models\BeatmapDiscussionVote;
use App\Models\Beatmapset;
use App\Models\User;
use App\Models\UserGroup;
use DB;
use Faker;
use Tests\TestCase;

class BeatmapDiscussionsControllerTest extends TestCase
{
    protected static $faker;

    public static function setUpBeforeClass(): void
    {
        self::$faker = Faker\Factory::create();
    }

    // normal vote
    public function testPutVoteInitial()
    {
        // can not vote as discussion starter
        $currentVotes = BeatmapDiscussionVote::count();
        $currentScore = $this->currentScore($this->discussion);

        $this
            ->actingAsVerified($this->user)
            ->put(route('beatmap-discussions.vote', $this->discussion), [
                'beatmap_discussion_vote' => ['score' => '1'],
            ])
            ->assertStatus(403);

        $this->assertSame($currentVotes, BeatmapDiscussionVote::count());
        $this->assertSame($currentScore, $this->currentScore($this->discussion));

        // and then no problem as another user
        $currentVotes = BeatmapDiscussionVote::count();
        $currentScore = $this->currentScore($this->discussion);

        $this
            ->actingAs($this->anotherUser)
            ->put(route('beatmap-discussions.vote', $this->discussion), [
                'beatmap_discussion_vote' => ['score' => '1'],
            ])
            ->assertStatus(200);

        $this->assertSame($currentVotes + 1, BeatmapDiscussionVote::count());
        $this->assertSame($currentScore + 1, $this->currentScore($this->discussion));

        // can not vote ranked maps
        $this->beatmapset->update(['approved' => Beatmapset::STATES['ranked']]);
        $moreUser = factory(User::class)->create();

        $currentVotes = BeatmapDiscussionVote::count();
        $currentScore = $this->currentScore($this->discussion);

        $this
            ->actingAs($moreUser)
            ->put(route('beatmap-discussions.vote', $this->discussion), [
                'beatmap_discussion_vote' => ['score' => '1'],
            ])
            ->assertStatus(403);

        $this->assertSame($currentVotes, BeatmapDiscussionVote::count());
        $this->assertSame($currentScore, $this->currentScore($this->discussion));
    }

    // changing vote (as BNG) only changes the score
    public function testPutVoteChangeBNG()
    {
        $this->discussion->vote([
            'score' => 1,
            'user_id' => $this->bngUser->user_id,
        ]);

        $currentVotes = BeatmapDiscussionVote::count();
        $currentScore = $this->currentScore($this->discussion);

        $this
            ->actingAsVerified($this->bngUser)
            ->put(route('beatmap-discussions.vote', $this->discussion), [
                'beatmap_discussion_vote' => ['score' => '-1'],
            ])
            ->assertStatus(200);

        $this->assertSame($currentVotes, BeatmapDiscussionVote::count());
        $this->assertSame($currentScore - 2, $this->currentScore($this->discussion));
    }

    // voting again has no effect
    public function testPutVoteChange()
    {
        $this->discussion->vote([
            'score' => 1,
            'user_id' => $this->anotherUser->user_id,
        ]);

        $currentVotes = BeatmapDiscussionVote::count();
        $currentScore = $this->currentScore($this->discussion);

        $this
            ->actingAsVerified($this->anotherUser)
            ->put(route('beatmap-discussions.vote', $this->discussion), [
                'beatmap_discussion_vote' => ['score' => '1'],
            ])
            ->assertStatus(200);

        $this->assertSame($currentVotes, BeatmapDiscussionVote::count());
        $this->assertSame($currentScore, $this->currentScore($this->discussion));
    }

    // voting 0 will remove the vote
    public function testPutVoteRemove()
    {
        $this->discussion->vote([
            'score' => 1,
            'user_id' => $this->anotherUser->user_id,
        ]);

        $currentVotes = BeatmapDiscussionVote::count();
        $currentScore = $this->currentScore($this->discussion);

        $this
            ->actingAsVerified($this->anotherUser)
            ->put(route('beatmap-discussions.vote', $this->discussion), [
                'beatmap_discussion_vote' => ['score' => '0'],
            ])
            ->assertStatus(200);

        $this->assertSame($currentVotes - 1, BeatmapDiscussionVote::count());
        $this->assertSame($currentScore - 1, $this->currentScore($this->discussion));
    }

    private function currentScore($discussion)
    {
        return (int) $discussion->fresh()->beatmapDiscussionVotes()->sum('score');
    }

    // downvote by regular user should fail
    public function testPutVoteDownChange()
    {
        $currentVotes = BeatmapDiscussionVote::count();
        $currentScore = $this->currentScore($this->discussion);

        $this
            ->actingAsVerified($this->anotherUser)
            ->put(route('beatmap-discussions.vote', $this->discussion), [
                'beatmap_discussion_vote' => ['score' => '-1'],
            ])
            ->assertStatus(403);

        $this->assertSame($currentVotes, BeatmapDiscussionVote::count());
        $this->assertSame($currentScore, $this->currentScore($this->discussion));
    }

    // downvote by BNG user should NOT fail
    public function testPutVoteDownChangeBNG()
    {
        $currentVotes = BeatmapDiscussionVote::count();
        $currentScore = $this->currentScore($this->discussion);

        $this
            ->actingAsVerified($this->bngUser)
            ->put(route('beatmap-discussions.vote', $this->discussion), [
                'beatmap_discussion_vote' => ['score' => '-1'],
            ])
            ->assertStatus(200);

        $this->assertSame($currentVotes + 1, BeatmapDiscussionVote::count());
        $this->assertSame($currentScore - 1, $this->currentScore($this->discussion));
    }

    // posting reviews - fail scenarios ----

    // guest user
    public function testPostReviewGuest()
    {
        $this
            ->post(route('beatmapsets.beatmap-discussions.review'))
            ->assertUnauthorized();
    }

    // beatmapset id missing
    public function testPostReviewIdMissing()
    {
        $this
            ->actingAsVerified($this->user)
            ->post(route('beatmapsets.beatmap-discussions.review'))
            ->assertStatus(404);
    }

    // invalid document
    public function testPostReviewDocumentMissing()
    {
        $this
            ->actingAsVerified($this->user)
            ->post(route('beatmapsets.beatmap-discussions.review'), [
                'beatmapset_id' => $this->beatmapset->getKey(),
            ])
            ->assertStatus(422);
    }

    // posting reviews - success scenario ----

    // valid document containing issue embeds
    public function testPostReviewDocumentValidWithIssues()
    {
        $discussionCount = BeatmapDiscussion::count();
        $discussionPostCount = BeatmapDiscussionPost::count();
        $timestampedIssueText = '00:01:234 '.self::$faker->sentence();
        $issueText = self::$faker->sentence();

        $this
            ->actingAsVerified($this->user)
            ->post(route('beatmapsets.beatmap-discussions.review'), [
                'beatmapset_id' => $this->beatmapset->getKey(),
                'document' => [
                    [
                        'type' => 'embed',
                        'discussion_type' => 'problem',
                        'text' => $timestampedIssueText,
                        'timestamp' => true,
                        'beatmap_id' => $this->beatmapset->beatmaps->first()->getKey(),
                    ],
                    [
                        'type' => 'embed',
                        'discussion_type' => 'problem',
                        'text' => $issueText,
                    ],
                ],
            ])
            ->assertSuccessful()
            ->assertJsonFragment(
              [
                  'user_id' => $this->user->getKey(),
                  'message' => $timestampedIssueText,
              ]
            )
            // ensure timestamp was parsed correctly
            ->assertJsonFragment(
                [
                    'timestamp' => 1234,
                ]
            )
            ->assertJsonFragment(
              [
                  'user_id' => $this->user->getKey(),
                  'message' => $issueText,
              ]
            );

        // ensure 3 discussions/posts are created - one for the review and one for each embedded problem
        $this->assertSame($discussionCount + 3, BeatmapDiscussion::count());
        $this->assertSame($discussionPostCount + 3, BeatmapDiscussionPost::count());
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('osu.beatmapset.discussion_review_enabled', true);

        $this->mapper = factory(User::class)->create();
        $this->user = factory(User::class)->create();
        $this->anotherUser = factory(User::class)->create();
        $this->bngUser = factory(User::class)->create();
        $this->bngUserGroup($this->bngUser);
        $this->beatmapset = factory(Beatmapset::class)->create([
            'user_id' => $this->mapper->user_id,
            'discussion_enabled' => true,
            'approved' => Beatmapset::STATES['pending'],
        ]);
        $this->beatmap = $this->beatmapset->beatmaps()->save(factory(Beatmap::class)->make([
            'user_id' => $this->mapper->user_id,
        ]));
        $this->discussion = BeatmapDiscussion::create([
            'beatmapset_id' => $this->beatmapset->getKey(),
            'timestamp' => 0,
            'message_type' => 'problem',
            'beatmap_id' => $this->beatmap->beatmap_id,
            'user_id' => $this->user->user_id,
        ]);
    }

    private function bngUserGroup($user)
    {
        $table = (new UserGroup)->getTable();

        $conditions = [
            'user_id' => $user->user_id,
            'group_id' => app('groups')->byIdentifier('bng')->getKey(),
        ];

        $existingUserGroup = UserGroup::where($conditions)->first();

        if ($existingUserGroup !== null) {
            return $existingUserGroup;
        }

        DB::table($table)->insert($conditions);

        return UserGroup::where($conditions)->first();
    }
}
