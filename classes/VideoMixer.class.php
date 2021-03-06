<?

class VideoMixer
{
  static $plugins = array();
  static $tmp_path = null;
  static $cp = null;
  
  static function init($path, $tmp_path)
  {
    self::$tmp_path = $tmp_path;
    if(!self::$cp)
    {
      self::$cp = new SingleCommandProcessor(self::$tmp_path);
    }

    foreach(glob($path."/plugins/VideoMixer_*.php") as $fname)
    {
      require_once($fname);
      preg_match("/VideoMixer_(.+).class.php/", $fname, $matches);
      $effect_name = $matches[1];
      $class_name = "VideoMixer_{$effect_name}";
      $obj = new $class_name();
      self::registerPlugin($effect_name, $obj);
    }
  }
  static function registerPlugin($key, $obj)
  {
    self::$plugins[$key] = $obj;
  }
  
  function __construct($fname, $marker_name=null)
  {
    $this->stitch_limit = 0; // frames
    $this->frames = array();
    $this->frame_count = 0;
    $this->markers = array();
    $this->assets = array();
    $this->md5 = $this->key($fname);
    $this->add($fname, $marker_name);
    
  }
  
  function key($fname)
  {
    if(!file_exists($fname)) dprint("File not found: $fname");
    return md5(VIDEO_EXT.md5_file($fname));
  }
  
  function add($fname, $asset_name = null)
  {
    if(!file_exists($fname)) dprint("File not found: $fname");
    $output = cmd("python qrotate.py ?", $fname);
    $degrees = $output[0];
    $extra = '';
    switch($degrees)
    {
      case 0:
        break;
      case 180:
        $extra = "-vf 'vflip,hflip'";
        break;
      default:
        dprint("Unhandled angle, {$degrees}");
    }
    // python for vflip
    self::$cp->setTemplate("ffmpeg -i ? -r ! ! -q:v 2 <outdir/%010d.!>");
    $unpacked_fname = self::$cp->add($fname, FRAME_RATE, $extra, VIDEO_EXT);
    self::$cp->process();
    if($asset_name==null)
    {
      $parts = pathinfo($fname);
      $asset_name = $parts['filename'];
    }
    if(isset( $this->assets[$asset_name])) dprint("Duplicate asset name $fname");
    $this->assets[$asset_name] = $unpacked_fname;
    return $unpacked_fname;
  }
  
  function stitch($asset_name, $marker=null)
  {
    if(!$marker) $marker = $asset_name;
    if(isset($this->markers[$marker])) dprint("Marker already in use $marker");
    $fpath = $this->assets[$asset_name];
    $c = count(glob($fpath."/*.".VIDEO_EXT));
    $this->markers[$marker] = count($this->frames);
    if($this->stitch_limit) $c = min($c, $this->stitch_limit);
    for($i=1;$i<=$c;$i++)
    {
      $this->frames[] = sprintf("%s/%010d.%s", $fpath, $i,VIDEO_EXT);
    }
    $this->markers['end'] = count($this->frames);
  }
  
  function applyEffect()
  {
    $args = func_get_args();
    $effect_name = array_shift($args);
    if(!isset(self::$plugins[$effect_name])) dprint("Effect $effect_name not found.");
    $start_marker = array_shift($args);
    $stop_marker = array_shift($args);
    if(is_numeric($start_marker))
    {
      $start_frame = $start_marker;
    } else {
      if(is_a($start_marker, 'Marker'))
      {
        $start_frame = $this->markers[$start_marker->marker] + $start_marker->offset;
      } else {
        $start_frame = $this->markers[$start_marker];
      }
    }
    if(is_numeric($stop_marker))
    {
      $stop_frame = $start_frame+$stop_marker-1;
    } else {
      if($stop_marker===null)
      {
        $stop_marker = 'end';
      }
      if(is_a($stop_marker, 'Marker'))
      {
        $stop_frame = $this->markers[$stop_marker->marker] + $stop_marker->offset - 1;
      } else {
        $stop_frame = $this->markers[$stop_marker] - 1;
      }
    }
    $frame_count = $stop_frame - $start_frame + 1;
    $frames = array_slice($this->frames, $start_frame, $frame_count);
    array_unshift($args, $frames);
    $new_frames = call_user_func_array(array(self::$plugins[$effect_name], 'process'), $args);
    self::$cp->process();
    $frame_diff = count($new_frames)-$frame_count;
    foreach($this->markers as $k=>$v)
    {
      if($v>$start_frame)
      {
        $this->markers[$k] = $v+$frame_diff;
      }
    }
    array_splice($this->frames, $start_frame, $frame_count, $new_frames);
  }
  
  function export($audio)
  {
    $start = microtime(true);
    $audio_fname = $audio->export();
    
    $key = md5(join('|',$this->frames).'|'.$audio_fname);

    $fpath = self::$tmp_path."/export/{$key}";
    $src_fname = sprintf("%s/%%010d.%s", $fpath, VIDEO_EXT);
    $dst_fname = sprintf("%s/out.mp4", $fpath);
    if(!file_exists($fpath))
    {
      $this->copy_frames($fpath);
      cmd("ffmpeg -y -r ? -i ? -i ? -map 0:v -r ! -map 1:a -vcodec libx264 -b:v 400k -b:a 192k -f mp4 -strict experimental -acodec aac -ar:1 ! ?", 
        FRAME_RATE,
        $src_fname, 
        $audio_fname,
        FRAME_RATE,
        SAMPLE_RATE,
        $dst_fname
      );
    }
    return $dst_fname;
  }
  
  function copy_frames($fpath)
  {
    ensure_writable_folder($fpath);
    for($i=0;$i<count($this->frames);$i++)
    {
      $src_fname = $this->frames[$i];
      $dst_fname = sprintf("%s/%010d.%s", $fpath, $i+1, VIDEO_EXT);
      copy($src_fname, $dst_fname);
    }
  }
  
}