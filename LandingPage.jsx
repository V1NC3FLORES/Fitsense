import React, { useEffect, useState } from 'react';
import { PlayCircle, MapPin, FacebookLogo, InstagramLogo, Barbell, Heartbeat, Users } from '@phosphor-icons/react';

const LandingPage = () => {
  const [isVisible, setIsVisible] = useState(false);

  useEffect(() => {
    // Simple fade-in animation trigger on load
    setIsVisible(true);
  }, []);

  return (
    <div className="font-poppins bg-[#0a0a0a] text-white min-h-screen">
      
      {/* Navbar */}
      <header className="flex justify-between items-center p-6 md:px-12 bg-black border-b border-gray-800 sticky top-0 z-50">
        <div className="text-3xl font-bold tracking-wider text-yellow-400">UMAK GYM</div>
        <nav>
          <a href="login.php" className="px-6 py-3 bg-yellow-400 text-black font-bold text-lg rounded hover:bg-yellow-300 transition-colors duration-300">
            MEMBER LOGIN
          </a>
        </nav>
      </header>

      <main className={`transition-opacity duration-1000 ${isVisible ? 'opacity-100' : 'opacity-0'}`}>
        
        {/* Hero Section */}
        <section className="relative w-full h-[80vh] flex flex-col justify-center items-center text-center px-4 bg-zinc-900 bg-opacity-80 border-b-8 border-yellow-400">
          <div className="absolute inset-0 bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-950 opacity-90"></div>
          <div className="relative z-10 max-w-4xl mx-auto">
            <h1 className="text-5xl md:text-7xl font-extrabold text-white mb-6 uppercase tracking-tight leading-tight shadow-sm">
              Start Your Journey To <span className="text-yellow-400">Strength</span>
            </h1>
            <p className="text-xl md:text-2xl text-gray-200 mb-10 font-medium">
              A community built on resilience, power, and accessible fitness for all.
            </p>
            <a href="user-register.php" className="inline-block px-10 py-4 bg-transparent border-4 border-yellow-400 text-yellow-400 font-bold text-2xl hover:bg-yellow-400 hover:text-black transition-all duration-300 transform hover:scale-105">
              JOIN NOW
            </a>
          </div>
        </section>

        {/* About Section */}
        <section className="py-20 px-6 md:px-12 max-w-6xl mx-auto text-center">
          <h2 className="text-4xl md:text-5xl font-bold uppercase mb-12 border-b-2 border-yellow-400 inline-block pb-4">
            We Believe That <span className="text-yellow-400">Strong Is Beautiful</span>
          </h2>
          <div className="relative w-full max-w-4xl mx-auto aspect-video bg-zinc-800 flex items-center justify-center rounded-lg overflow-hidden group cursor-pointer border border-gray-700">
            <PlayCircle size={96} className="text-yellow-400 group-hover:scale-110 transition-transform duration-300" weight="fill" />
            <span className="absolute text-xl font-bold mt-32 text-gray-300">WATCH OUR STORY</span>
          </div>
          <p className="mt-12 text-xl md:text-2xl text-gray-300 leading-relaxed max-w-4xl mx-auto">
            Our facility is equipped with state-of-the-art machines and guided by expert coaches dedicated to pushing your limits safely. Whether you are a beginner or a seasoned athlete, you belong here.
          </p>
        </section>

        {/* Facility / Programs Section */}
        <section className="py-20 bg-zinc-950 px-6 md:px-12 border-t border-b border-gray-800">
          <div className="max-w-7xl mx-auto">
            <h2 className="text-4xl md:text-5xl font-bold text-center mb-16 text-yellow-400 uppercase">What We Offer</h2>
            
            <div className="grid grid-cols-1 md:grid-cols-3 gap-10">
              {/* Card 1 */}
              <article className="bg-zinc-900 p-8 rounded border border-gray-800 hover:border-yellow-400 transition-all duration-300 hover:-translate-y-2">
                <Barbell size={64} className="text-yellow-400 mb-6" weight="duotone" />
                <h3 className="text-3xl font-bold mb-4">Strength Training</h3>
                <p className="text-xl text-gray-400 leading-relaxed">Top-tier free weights and resistance machines to build muscle and power.</p>
              </article>
              
              {/* Card 2 */}
              <article className="bg-zinc-900 p-8 rounded border border-gray-800 hover:border-yellow-400 transition-all duration-300 hover:-translate-y-2">
                <Heartbeat size={64} className="text-yellow-400 mb-6" weight="duotone" />
                <h3 className="text-3xl font-bold mb-4">Cardio & HIIT</h3>
                <p className="text-xl text-gray-400 leading-relaxed">Dedicated zones for high-intensity intervals and cardiovascular endurance.</p>
              </article>

              {/* Card 3 */}
              <article className="bg-zinc-900 p-8 rounded border border-gray-800 hover:border-yellow-400 transition-all duration-300 hover:-translate-y-2">
                <Users size={64} className="text-yellow-400 mb-6" weight="duotone" />
                <h3 className="text-3xl font-bold mb-4">Expert Coaching</h3>
                <p className="text-xl text-gray-400 leading-relaxed">Certified trainers providing customized plans to hit your personal fitness goals.</p>
              </article>
            </div>
          </div>
        </section>

        {/* Brand Pillars */}
        <section className="py-24 px-6 max-w-4xl mx-auto text-center">
          <h2 className="text-4xl md:text-5xl font-bold uppercase mb-16">Our Brand Pillars</h2>
          <div className="space-y-12">
            <div>
              <span className="text-yellow-400 text-6xl font-black block mb-4">1</span>
              <h3 className="text-3xl font-bold">ACCESSIBLE FITNESS</h3>
            </div>
            <div>
              <span className="text-yellow-400 text-6xl font-black block mb-4">2</span>
              <h3 className="text-3xl font-bold">COMMUNITY DRIVEN</h3>
            </div>
            <div>
              <span className="text-yellow-400 text-6xl font-black block mb-4">3</span>
              <h3 className="text-3xl font-bold">RESULTS ORIENTED</h3>
            </div>
          </div>
        </section>

        {/* CTA Section */}
        <section className="py-20 bg-yellow-400 text-black text-center px-4">
          <h2 className="text-5xl md:text-6xl font-black uppercase mb-6">Strength Is A Process!</h2>
          <p className="text-2xl font-bold mb-10">Start building yours today.</p>
          <a href="login.php" className="inline-block px-12 py-5 bg-black text-yellow-400 font-extrabold text-2xl rounded hover:bg-zinc-800 transition-colors shadow-lg">
            LOGIN TO YOUR DASHBOARD
          </a>
        </section>

      </main>

      {/* Footer */}
      <footer className="bg-black py-16 px-6 border-t-4 border-yellow-400">
        <div className="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-start gap-10">
          <div className="max-w-md">
            <h3 className="text-3xl font-bold text-yellow-400 mb-6">UMAK GYM</h3>
            <p className="text-xl text-gray-400 mb-6 flex items-center gap-3">
              <MapPin size={32} weight="fill" className="text-yellow-400 flex-shrink-0" />
              University of Makati, Taguig, Metro Manila
            </p>
          </div>
          <div>
            <h4 className="text-2xl font-bold mb-6 text-white">CONNECT WITH US</h4>
            <div className="flex gap-6">
              <a href="#" aria-label="Facebook" className="text-yellow-400 hover:text-white transition-colors"><FacebookLogo size={48} weight="fill" /></a>
              <a href="#" aria-label="Instagram" className="text-yellow-400 hover:text-white transition-colors"><InstagramLogo size={48} weight="fill" /></a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
};

export default LandingPage;