<?

class CrossfadeEffect
{
  function process($cp, $frames)
  {
    $frame_count = count($frames);
    if($frame_count % 2 != 0) dprint("Frame count must be even for crossfade");
    $offset = $frame_count/2;
    $cp->setTemplate("composite -dissolve !x! ? ? -alpha Set <out>");
    $tween = new SinTween($offset);
    $new_frames = array();
    for($i=0;$i<$offset;$i++)
    {
      $step = $tween->step();
      $new_frames[] = $cp->add($step, 100-$step, $frames[$i], $frames[$i+$offset]);
    }
    return $new_frames;
  }
}