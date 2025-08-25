document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const navbarLinks = document.getElementById('navbarLinks');
    
    if (mobileMenuBtn && navbarLinks) {
        mobileMenuBtn.addEventListener('click', function() {
            navbarLinks.classList.toggle('active');
        });
    }
    
    // Navbar scroll effect
    window.addEventListener('scroll', function() {
        const navbar = document.getElementById('navbar');
        if (window.scrollY > 50) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    });
});

// About Section
document.addEventListener('DOMContentLoaded', () => {
  const scrollElements = document.querySelectorAll('.scroll-fade');
  const aboutContainer = document.querySelector('.hero-about-container');

  const elementInView = (el, offset = 100) => {
    const elementTop = el.getBoundingClientRect().top;
    return elementTop <= (window.innerHeight - offset);
  };

  const displayScrollElement = (element) => {
    element.classList.add('show');
  };

  const handleScrollAnimation = () => {
    scrollElements.forEach((el) => {
      if (elementInView(el)) {
        displayScrollElement(el);
      }
    });

    if (aboutContainer && elementInView(aboutContainer, 150)) {
      aboutContainer.classList.add('show');
    }
  };

  window.addEventListener('scroll', handleScrollAnimation);
  handleScrollAnimation(); // Trigger on load
});




