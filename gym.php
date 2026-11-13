<?php
declare(strict_types=1);
/**
 * MCCodes v2 by Dabomstew & ColdBlooded
 * 
 * Repository: https://github.com/davemacaulay/mccodesv2
 * License: MIT License
 */

$macropage = 'gym.php';
global $db, $ir, $userid, $h;
require_once('globals.php');
if ($ir['hospital'])
{
    die('This page cannot be accessed while in hospital.');
}
$statnames =
        ['Strength' => 'strength', 'Agility' => 'agility',
                'Guard' => 'guard', 'Labour' => 'labour'];
$_POST['amnt'] = in_int('amnt', 0, $_POST);
if ($ir['jail'] <= 0)
{
    echo '<h3>Gym</h3><hr />';
}
else
{
    echo '<h3>Jail Gym</h3><hr />';
}
if (isset($_POST['stat']) && $_POST['amnt'])
{
    if (!verify_csrf_code('gym_train', in_str('verf', 64, '', $_POST)))
    {
        echo 'That form had expired. Please try again.<hr />';
        $_POST['amnt'] = 0;
    }
}
if (isset($_POST['stat']) && $_POST['amnt'])
{
    if (!isset($statnames[$_POST['stat']]))
    {
        die('This stat cannot be trained.');
    }
    $stat = $statnames[$_POST['stat']];
    // the energy is taken first, in one statement that refuses if it is not there; only then does the training happen
    if (!bar_spend($userid, 'energy', $_POST['amnt']))
    {
        print('You do not have enough energy to train that much.<hr />');
    }
    else
    {
        $gain = 0;
        for ($i = 0; $i < $_POST['amnt']; $i++)
        {
            $gain +=
                    rand(1, 3) / rand(800, 1000) * rand(800, 1000)
                            * (($ir['will'] + 20) / 150);
            $ir['will'] -= rand(1, 3);
            if ($ir['will'] < 0)
            {
                $ir['will'] = 0;
            }
        }
        if ($ir['jail'] > 0)
        {
            $gain /= 2;
        }
        $db->exec("UPDATE `userstats` SET `{$stat}` = `{$stat}` + ? WHERE `userid` = ?", [$gain, $userid]);
        $db->exec('UPDATE `users` SET `will` = ? WHERE `userid` = ?', [$ir['will'], $userid]);
        $inc = $ir[$stat] + $gain;
        $inc2 = $ir['energy'] - $_POST['amnt'];
        if ($stat == 'strength')
        {
            echo "You begin lifting some weights.<br />
      You have gained {$gain} strength by doing {$_POST['amnt']} sets of weights.<br />
      You now have {$inc} strength and {$inc2} energy left.";
        }
        elseif ($stat == 'agility')
        {
            echo "You begin running on a treadmill.<br />
      You have gained {$gain} agility by doing {$_POST['amnt']} minutes of running.<br />
      You now have {$inc} agility and {$inc2} energy left.";
        }
        elseif ($stat == 'guard')
        {
            echo "You jump into the pool and begin swimming.<br />
      You have gained {$gain} guard by doing {$_POST['amnt']} minutes of swimming.<br />
      You now have {$inc} guard and {$inc2} energy left.";
        }
        elseif ($stat == 'labour')
        {
            echo "You walk over to some boxes filled with gym equipment and start moving them.<br />
      You have gained {$gain} labour by moving {$_POST['amnt']} boxes.<br />
      You now have {$inc} labour and {$inc2} energy left.";
        }
        echo '<hr />';
        $ir['energy'] -= $_POST['amnt'];
        $ir[$stat] += $gain;
    }
}
$ir['strank'] = get_rank($ir['strength'], 'strength');
$ir['agirank'] = get_rank($ir['agility'], 'agility');
$ir['guarank'] = get_rank($ir['guard'], 'guard');
$ir['labrank'] = get_rank($ir['labour'], 'labour');
echo "Choose the stat you want to train and the times you want to train it.<br />
You can train up to {$ir['energy']} times.<hr />
<form action='gym.php' method='post'>" . request_csrf_html('gym_train') . "
Stat: <select type='dropdown' name='stat'>
<option style='color:red;' value='Strength'>Strength (Have {$ir['strength']}, Ranked {$ir['strank']})
<option style='color:blue;' value='Agility'>Agility (Have {$ir['agility']}, Ranked {$ir['agirank']})
<option style='color:green;' value='Guard'>Guard (Have {$ir['guard']}, Ranked {$ir['guarank']})
<option style='color:brown;' value='Labour'>Labour (Have {$ir['labour']}, Ranked {$ir['labrank']})
</select><br />
Times to train: <input type='text' name='amnt' value='{$ir['energy']}' /><br />
<input type='submit' value='Train' /></form>";
$h->endpage();
